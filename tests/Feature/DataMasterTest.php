<?php

namespace Tests\Feature;

use App\Enums\Izin;
use App\Enums\KeputusanTunggakan;
use App\Enums\StatusSantri;
use App\Enums\TahapPeringatan;
use App\Exceptions\AturanDilanggar;
use App\Models\Kelas;
use App\Models\Peringatan;
use App\Models\TahunAjaran;
use App\Services\DataSantriService;
use App\Services\PeriodeService;
use Tests\KhandaqTestCase;

class DataMasterTest extends KhandaqTestCase
{
    public function test_admin_office_menambah_santri_dengan_kode_unik_otomatis(): void
    {
        $ao = $this->beriIzin($this->buatUser('TU'), Izin::SantriKelola);
        $ta = TahunAjaran::untukTanggal($this->tgl('2026-07-01'));
        $svc = new DataSantriService();
        $a = $svc->tambah(['nis' => '262701', 'nama' => 'Santri Baru A', 'jenis_kelamin' => 'laki-laki'], Kelas::where('nama', '1 PUTRA')->first(), $ta, $ao);
        $b = $svc->tambah(['nis' => '262702', 'nama' => 'Santri Baru B', 'jenis_kelamin' => 'laki-laki'], Kelas::where('nama', '1 PUTRA')->first(), $ta, $ao);
        $this->assertSame('101', $a->kode_unik);
        $this->assertSame('102', $b->kode_unik);
        $this->assertSame('1 PUTRA', $a->kelasPada($ta)->nama);

        $this->expectException(AturanDilanggar::class);
        $svc->tambah(['nis' => '262703', 'nama' => 'X', 'jenis_kelamin' => 'laki-laki'], Kelas::first(), $ta, $this->keuangan());
    }

    public function test_kenaikan_kelas_massal_dan_kelulusan_membebaskan_kode_unik(): void
    {
        $ao = $this->beriIzin($this->buatUser('TU'), Izin::SantriKelola);
        $s1 = $this->santri('1 PUTRA', '301');
        $s6 = $this->santri('6', '306');
        $dari = TahunAjaran::untukTanggal($this->tgl('2025-07-01'));
        $ke = TahunAjaran::untukTanggal($this->tgl('2026-07-01'));
        $k = fn ($n) => Kelas::where('nama', $n)->first()->id;

        $hasil = (new DataSantriService())->naikkanKelas($dari, $ke, [$k('1 PUTRA') => $k('2 PUTRA'), $k('6') => null], $ao);
        $this->assertSame(['naik' => 1, 'lulus' => 1], $hasil);
        $this->assertSame('2 PUTRA', $s1->fresh()->kelasPada($ke)->nama);
        $this->assertSame(StatusSantri::Alumni, $s6->fresh()->status);
        $this->assertNull($s6->fresh()->kode_unik);
    }

    public function test_pemulangan_karena_tunggakan_butuh_keputusan_keuangan(): void
    {
        $ao = $this->beriIzin($this->buatUser('TU'), Izin::SantriKelola);
        $s = $this->santri('3 PUTRA', '401');
        $svc = new DataSantriService();
        try {
            $svc->keluarkan($s, 'Tunggakan 3 bulan', $ao, karenaTunggakan: true);
            $this->fail('harus ada keputusan Keuangan');
        } catch (AturanDilanggar) {
        }
        Peringatan::create(['santri_id' => $s->id, 'tahap' => TahapPeringatan::Terlambat3, 'bulan_acuan' => '2026-09-01',
            'keputusan' => KeputusanTunggakan::Pemulangan]);
        $this->assertSame(StatusSantri::Keluar, $svc->keluarkan($s, 'Tunggakan 3 bulan', $ao, karenaTunggakan: true)->status);
    }

    public function test_admin_mengaktifkan_semester_berjalan(): void
    {
        $admin = $this->beriIzin($this->buatUser('Admin'), Izin::PeriodeKelola);
        $ta = TahunAjaran::untukTanggal($this->tgl('2026-07-01'));
        $smt = $ta->semesterUntuk($this->tgl('2026-09-27'));
        (new PeriodeService())->aktifkan($smt, $admin);
        $this->assertSame('2026/2027', TahunAjaran::aktif()->nama);
        $this->assertTrue($smt->fresh()->aktif);
    }
}
