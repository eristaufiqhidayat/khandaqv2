<?php

namespace Tests\Feature;

use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Tarif;
use App\Models\TahunAjaran;
use App\Services\LaporanTagihan;
use Tests\KhandaqTestCase;

class LaporanTagihanTest extends KhandaqTestCase
{
    public function test_kisi_spp_per_santri_dan_ringkasan_kartu(): void
    {
        $rajin = $this->santri('3 PUTRA');
        $telat = $this->santri('1 PUTRA');
        foreach (['2025-07-01', '2025-08-01', '2025-09-01'] as $b) {
            $this->generator()->bulanan($this->tgl($b));
        }
        $this->tabungan()->catatSetoran($rajin, 6_000_000, $this->tgl('2025-09-05'), false, $this->adminOffice());

        $ta = TahunAjaran::untukTanggal($this->tgl('2025-07-01'));
        $lap = new LaporanTagihan();
        $rows = $lap->rekapSpp($ta, $this->tgl('2025-09-15'));

        $this->assertSame($telat->id, $rows[0]['santri']->id, 'penunggak di atas');
        $this->assertSame(2, $rows[0]['bulan_terlambat'], 'Juli & Agustus lewat jatuh tempo');
        $this->assertSame(2 * 1_400_000, $rows[0]['sisa_terlambat']);
        $this->assertSame('belum', $rows[0]['bulan']['2025-09-01'], 'September belum jatuh tempo');
        $this->assertSame('-', $rows[0]['bulan']['2025-10-01']);
        $this->assertSame('lunas', $rows[1]['bulan']['2025-07-01']);

        $this->assertCount(1, $lap->rekapSpp($ta, $this->tgl('2025-09-15'), status: 'menunggak'));
        $this->assertCount(1, $lap->rekapSpp($ta, $this->tgl('2025-09-15'), Kelas::where('nama', '3 PUTRA')->first()));

        $r = $lap->ringkasanSpp($ta, $this->tgl('2025-09-15'));
        $this->assertSame(['berbayar' => 2, 'lunas' => 1, 'persen' => 50.0, 'menunggak' => 1, 'total_tunggakan' => 2_800_000], $r);
    }

    public function test_rekap_dsb_du_menampilkan_sisa_cicilan(): void
    {
        $ta = TahunAjaran::untukTanggal($this->tgl('2025-07-01'));
        Tarif::create(['jenis_tagihan_id' => JenisTagihan::kode('DSB')->id, 'tahun_ajaran_id' => $ta->id, 'berlaku_mulai' => '2025-07-01', 'nominal' => 5_000_000]);
        $s = $this->santri('1 PUTRA', masuk: '2025-07-12');
        $dsb = $this->generator()->dsb($s, $ta, $this->tgl('2025-12-31'));
        $ao = $this->adminOffice();
        $this->tabungan()->catatSetoran($s, 2_015_000, $this->tgl('2025-07-15'), false, $ao);
        $this->tabungan()->bayarTagihan($dsb, 2_000_000, $this->tgl('2025-07-15'), $ao);

        $rows = (new LaporanTagihan())->rekapDsbDu($ta);
        $this->assertSame('DSB', $rows[0]['jenis']);
        $this->assertSame(3_000_000, $rows[0]['sisa']);
        $this->assertSame('sebagian', $rows[0]['status']);
    }
}
