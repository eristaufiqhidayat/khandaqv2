<?php

namespace Tests\Feature;

use App\Enums\StatusMutasi;
use App\Enums\StatusTagihan;
use App\Exceptions\AturanDilanggar;
use Tests\KhandaqTestCase;

class SetoranDanPotonganTest extends KhandaqTestCase
{
    public function test_transfer_pending_tidak_menambah_saldo_sampai_diverifikasi_orang_lain(): void
    {
        $s = $this->santri('3 PUTRA');
        $ao = $this->adminOffice();
        $m = $this->tabungan()->catatSetoran($s, 2_000_000, $this->tgl('2026-02-05 09:00'), true, $ao);
        $this->assertSame(StatusMutasi::Pending, $m->status);
        $this->assertSame(0, $s->saldo());

        $this->expectException(AturanDilanggar::class);
        $this->tabungan()->verifikasi($m, $ao); // pencatat = verifikator -> ditolak
    }

    public function test_setelah_verifikasi_infak_spp_laundry_kesehatan_terpotong_otomatis_sisanya_uang_saku(): void
    {
        $s = $this->santri('3 PUTRA');
        $this->generator()->bulanan($this->tgl('2026-02-01'));
        $wali = $this->wali($s);

        $m = $this->tabungan()->catatSetoran($s, 2_500_000, $this->tgl('2026-02-05 09:00'), true, $wali, 'bukti/a.jpg');
        $this->tabungan()->verifikasi($m, $this->adminOffice());

        // 2.500.000 - infak 15.000 - SPP 1.700.000 - laundry 100.000 - kesehatan 50.000
        $this->assertSame(635_000, $s->saldo());
        $this->assertSame(0, $s->tagihan()->whereIn('status', StatusTagihan::terbuka())->count());
    }

    public function test_spp_tidak_dicicil_otomatis_bila_saldo_kurang(): void
    {
        $s = $this->santri('3 PUTRA');
        $this->generator()->bulanan($this->tgl('2026-02-01'));
        $this->tabungan()->catatSetoran($s, 500_000, $this->tgl('2026-02-05'), false, $this->adminOffice());

        $spp = $s->tagihan()->whereHas('jenisTagihan', fn ($q) => $q->where('kode', 'SPP'))->first();
        $this->assertSame(StatusTagihan::Belum, $spp->status, 'SPP menunggu saldo cukup, uang saku tidak habis sebagian');
        $this->assertSame(500_000 - 15_000 - 100_000 - 50_000, $s->saldo());
    }

    public function test_tarik_tunai_tidak_boleh_membuat_saldo_negatif(): void
    {
        $s = $this->santri('4');
        $ao = $this->adminOffice();
        $this->tabungan()->catatSetoran($s, 115_000, $this->tgl('2026-03-02'), false, $ao);
        $this->tabungan()->tarikTunai($s, 60_000, $this->tgl('2026-03-03'), $ao);
        $this->assertSame(40_000, $s->saldo());

        $this->expectException(AturanDilanggar::class);
        $this->tabungan()->tarikTunai($s, 50_000, $this->tgl('2026-03-04'), $ao);
    }

    public function test_bayar_dsb_dari_tabungan_boleh_dicicil(): void
    {
        $s = $this->santri('1 PUTRA', masuk: '2025-07-12');
        $ta = \App\Models\TahunAjaran::untukTanggal($this->tgl('2025-07-01'));
        \App\Models\Tarif::create(['jenis_tagihan_id' => \App\Models\JenisTagihan::kode('DSB')->id, 'tahun_ajaran_id' => $ta->id,
            'berlaku_mulai' => '2025-07-01', 'nominal' => 5_000_000]);
        $dsb = $this->generator()->dsb($s, $ta, $this->tgl('2025-12-31'));
        $ao = $this->adminOffice();
        $this->tabungan()->catatSetoran($s, 2_015_000, $this->tgl('2025-07-15'), false, $ao);

        $this->tabungan()->bayarTagihan($dsb, 2_000_000, $this->tgl('2025-07-15'), $ao);
        $this->assertSame(StatusTagihan::Sebagian, $dsb->fresh()->status);
        $this->assertSame(3_000_000, $dsb->fresh()->sisa());
    }
}
