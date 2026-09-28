<?php

namespace Tests\Feature;

use App\Enums\KeputusanTunggakan;
use App\Enums\TahapPeringatan;
use App\Exceptions\AturanDilanggar;
use App\Exceptions\PeriodeTerkunci;
use App\Models\Peringatan;
use App\Models\TabunganMutasi;
use App\Models\TahunAjaran;
use App\Notifiers\LogNotifier;
use App\Services\LaporanCashflow;
use App\Services\PeringatanService;
use App\Services\TutupBukuService;
use Tests\KhandaqTestCase;

class PeringatanDanTutupBukuTest extends KhandaqTestCase
{
    public function test_tahapan_tunggakan_1_2_3_bulan_tanpa_denda_dan_idempoten(): void
    {
        $s = $this->santri('3 PUTRA');
        foreach (['2025-09-01', '2025-10-01', '2025-11-01'] as $b) {
            $this->generator()->bulanan($this->tgl($b));
        }
        $notif = new LogNotifier();
        $svc = new PeringatanService($notif);

        $svc->jalankan($this->tgl('2025-10-01'));
        $svc->jalankan($this->tgl('2025-11-01'));
        $svc->jalankan($this->tgl('2025-12-01'));
        $svc->jalankan($this->tgl('2025-12-02')); // hari berikutnya: tidak mengirim ulang

        $this->assertSame(['terlambat_1', 'terlambat_2', 'terlambat_3'], array_column($notif->terkirim, 'tahap'));
        $p3 = Peringatan::where('tahap', TahapPeringatan::Terlambat3->value)->first();
        $this->assertSame(3, $p3->bulan_tunggakan);
        $this->assertSame(3 * 1_700_000, $p3->total_tunggakan, 'tanpa denda');
        $this->assertStringContainsString('dipulangkan', $notif->terkirim[2]['pesan']);
    }

    public function test_keputusan_pemulangan_hanya_oleh_keuangan_setelah_wali_konfirmasi_baca(): void
    {
        $s = $this->santri('3 PUTRA');
        $wali = $this->wali($s);
        foreach (['2025-09-01', '2025-10-01', '2025-11-01'] as $b) {
            $this->generator()->bulanan($this->tgl($b));
        }
        (new PeringatanService(new LogNotifier()))->jalankan($this->tgl('2025-12-01'));
        $p = Peringatan::firstOrFail();
        $svc = new PeringatanService(new LogNotifier());

        try {
            $svc->putuskan($p, KeputusanTunggakan::Pemulangan, $this->keuangan());
            $this->fail('harus dikonfirmasi dibaca dulu');
        } catch (AturanDilanggar) {
        }
        $svc->tandaiDibaca($p, $wali);
        $this->assertSame(KeputusanTunggakan::Pemulangan, $svc->putuskan($p->fresh(), KeputusanTunggakan::Pemulangan, $this->keuangan())->keputusan);

        $this->expectException(AturanDilanggar::class);
        $svc->putuskan($p->fresh(), KeputusanTunggakan::Cicilan, $this->adminOffice());
    }

    public function test_pengingat_tiga_hari_sebelum_akhir_bulan_bila_saldo_kurang(): void
    {
        $s = $this->santri('3 PUTRA');
        $this->generator()->bulanan($this->tgl('2026-01-01'));
        $notif = new LogNotifier();
        (new PeringatanService($notif))->jalankan($this->tgl('2026-01-28'));
        $this->assertSame('pengingat', $notif->terkirim[0]['tahap']);
    }

    public function test_tutup_buku_mengunci_transaksi_bulan_itu(): void
    {
        $s = $this->santri('4');
        $ao = $this->adminOffice();
        $keu = $this->keuangan();
        $this->tabungan()->catatSetoran($s, 215_000, $this->tgl('2026-01-10'), false, $ao);
        $pending = $this->tabungan()->catatSetoran($s, 100_000, $this->tgl('2026-01-20'), true, $ao);

        $tb = new TutupBukuService();
        try {
            $tb->tutup($this->tgl('2026-01-01'), $keu, saatIni: $this->tgl('2026-02-03'));
            $this->fail('setoran pending harus diselesaikan dulu');
        } catch (AturanDilanggar) {
        }
        $this->tabungan()->verifikasi($pending, $keu);
        $buku = $tb->tutup($this->tgl('2026-01-01'), $keu, saatIni: $this->tgl('2026-02-03'));
        $this->assertSame($s->saldo($this->tgl('2026-01-31 23:59:59')), $buku->saldo_titipan);

        $this->expectException(PeriodeTerkunci::class);
        $this->tabungan()->tarikTunai($s, 10_000, $this->tgl('2026-01-31'), $ao);
    }

    public function test_cashflow_bulanan_konsisten_dengan_saldo_titipan(): void
    {
        $a = $this->santri('3 PUTRA');
        $b = $this->santri('1 PUTRI');
        $ao = $this->adminOffice();
        $this->generator()->bulanan($this->tgl('2025-08-01'));
        $m = $this->tabungan()->catatSetoran($a, 2_000_000, $this->tgl('2025-08-03'), true, $this->wali($a));
        $this->tabungan()->verifikasi($m, $ao);
        $this->tabungan()->catatSetoran($b, 1_700_000, $this->tgl('2025-08-04'), false, $ao);
        $this->tabungan()->tarikTunai($a, 50_000, $this->tgl('2025-08-10'), $ao);

        $rows = (new LaporanCashflow())->bulanan(TahunAjaran::untukTanggal($this->tgl('2025-08-01')));
        $this->assertCount(12, $rows);
        $this->assertSame('2025-07', $rows[0]['bulan']);
        $agu = $rows[1];
        $this->assertSame(['transfer' => 2_000_000, 'tunai' => 1_700_000], $agu['kas_bank_masuk']);
        $this->assertSame(1_700_000 + 1_400_000, $agu['pendapatan_per_dana']['SPP'] ?? 0, 'SPP kelas 3 + kelas 1 terpotong otomatis');
        $masuk = array_sum($agu['kas_bank_masuk']) + $agu['titipan_lain_masuk'];
        $keluar = $agu['kas_keluar_penarikan'] + array_sum($agu['pendapatan_per_dana']) + $agu['titipan_koreksi_keluar'];
        $this->assertSame($agu['saldo_titipan_awal'] + $masuk - $keluar, $agu['saldo_titipan_akhir']);
        $this->assertSame($a->saldo() + $b->saldo(), $rows[11]['saldo_titipan_akhir']);
    }
}
