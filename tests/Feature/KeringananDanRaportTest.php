<?php

namespace Tests\Feature;

use App\Exceptions\AturanDilanggar;
use App\Models\JenisTagihan;
use App\Models\Raport;
use App\Models\TahunAjaran;
use Tests\KhandaqTestCase;

class KeringananDanRaportTest extends KhandaqTestCase
{
    public function test_hanya_keuangan_yang_boleh_menyetujui(): void
    {
        $s = $this->santri();
        $ta = TahunAjaran::untukTanggal($this->tgl('2025-07-01'));
        $ao = $this->adminOffice();
        $k = $this->keringananSvc()->ajukanDiskon($s, $ta, JenisTagihan::kode('DSB'), null, 1_000_000, 'Kakak beradik', $ao);

        try {
            $this->keringananSvc()->setujui($k, $ao);
            $this->fail('Admin Office tidak boleh menyetujui');
        } catch (AturanDilanggar) {
        }

        $keu = $this->keuangan();
        $this->beriIzin($keu, \App\Enums\Izin::KeringananAjukan);
        $k2 = $this->keringananSvc()->ajukanDiskon($s, $ta, JenisTagihan::kode('DU'), 10, null, 'Uji', $keu);
        $this->expectException(AturanDilanggar::class);
        $this->keringananSvc()->setujui($k2, $keu); // pengaju = penyetuju
    }

    public function test_diskon_hanya_untuk_dsb_atau_du(): void
    {
        $this->expectException(AturanDilanggar::class);
        $this->keringananSvc()->ajukanDiskon($this->santri(), TahunAjaran::untukTanggal($this->tgl('2025-07-01')),
            JenisTagihan::kode('SPP'), 10, null, 'x', $this->adminOffice());
    }

    public function test_raport_terkunci_bila_spp_lewat_jatuh_tempo_dan_terbuka_dengan_dispensasi(): void
    {
        $s = $this->santri('3 PUTRA');
        $wali = $this->wali($s);
        $waliLain = $this->wali($this->santri());
        $ta = TahunAjaran::untukTanggal($this->tgl('2025-07-01'));
        $ganjil = $ta->semesterUntuk($this->tgl('2025-12-01'));
        $raport = Raport::create(['santri_id' => $s->id, 'semester_id' => $ganjil->id, 'jenis' => 'pas',
            'file_path' => 'raport/x.pdf', 'diterbitkan_pada' => '2025-12-20 08:00']);
        $akses = $this->aksesRaport();
        $saat = $this->tgl('2025-12-21');

        $this->assertTrue($akses->bolehLihat($wali, $raport, $saat), 'tanpa tunggakan -> terbuka');
        $this->assertFalse($akses->bolehLihat($waliLain, $raport, $saat), 'wali lain tidak boleh');

        $this->generator()->bulanan($this->tgl('2025-11-01')); // SPP Nov jatuh tempo 30 Nov, belum dibayar
        $this->assertFalse($akses->bolehLihat($wali, $raport, $saat));
        $this->assertStringContainsString('SPP November 2025', $akses->alasanTerkunci($s, $ganjil, $saat));
        $this->assertTrue($akses->bolehLihat($this->adminOffice(), $raport, $saat), 'staf tetap bisa melihat');

        $k = $this->keringananSvc()->ajukanDispensasiRaport($s, $ganjil, $this->tgl('2026-01-15'), 'Janji bayar', $this->adminOffice());
        $this->keringananSvc()->setujui($k, $this->keuangan());
        $this->assertTrue($akses->bolehLihat($wali, $raport, $saat), 'dispensasi Keuangan membuka raport');
        $this->assertSame(1, $s->tunggakan($saat)->whereHas('jenisTagihan', fn ($q) => $q->where('kode', 'SPP'))->count(), 'tunggakan tetap tercatat');
    }

    public function test_raport_belum_diterbitkan_tidak_terlihat_wali(): void
    {
        $s = $this->santri();
        $ta = TahunAjaran::untukTanggal($this->tgl('2025-07-01'));
        $r = Raport::create(['santri_id' => $s->id, 'semester_id' => $ta->semesterUntuk($this->tgl('2025-09-01'))->id,
            'jenis' => 'pts', 'file_path' => 'raport/y.pdf']);
        $this->assertFalse($this->aksesRaport()->bolehLihat($this->wali($s), $r, $this->tgl('2025-10-01')));
    }
}
