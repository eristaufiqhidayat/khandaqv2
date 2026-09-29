<?php

namespace Tests\Feature;

use App\Enums\Izin;
use App\Enums\StatusPendaftaran;
use App\Enums\StatusSantri;
use App\Exceptions\AturanDilanggar;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Tarif;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\DataSantriService;
use App\Services\DataWaliService;
use App\Services\PendaftaranService;
use App\Services\TagihanGenerator;
use Tests\KhandaqTestCase;

class PendaftaranDanWaliTest extends KhandaqTestCase
{
    private function svc(): PendaftaranService
    {
        return new PendaftaranService(new DataSantriService(), new TagihanGenerator());
    }

    private function tu(): User
    {
        return $this->beriIzin($this->buatUser('TU'), Izin::PendaftaranProses, Izin::SantriKelola, Izin::DataWaliVerifikasi);
    }

    private function formulir(string $nama, string $telepon): array
    {
        return ['nama_calon' => $nama, 'jenis_kelamin' => 'laki-laki', 'asal_sekolah' => 'SD Contoh', 'nama_wali' => 'Bapak Contoh',
            'hubungan_wali' => 'ayah', 'telepon_wali' => $telepon, 'alamat' => 'Jl. Contoh 1'];
    }

    public function test_pendaftaran_diterima_menjadi_santri_calon_akun_wali_dan_tagihan_dsb(): void
    {
        $ta = TahunAjaran::untukTanggal($this->tgl('2027-07-01'));
        Tarif::create(['jenis_tagihan_id' => JenisTagihan::kode('DSB')->id, 'tahun_ajaran_id' => $ta->id, 'berlaku_mulai' => '2027-07-01', 'nominal' => 5_000_000]);
        $tu = $this->tu();
        $p = $this->svc()->daftar($this->formulir('Calon Satu', '+62 812-3456-7890'), $ta);
        $this->assertSame('PSB-2027-001', $p->nomor);
        $this->assertSame('081234567890', $p->telepon_wali);

        try {
            $this->svc()->terima($p, Kelas::where('nama', '1 PUTRA')->first(), '272801', $this->tgl('2027-12-31'), $tu);
            $this->fail('formulir harus lunas dulu');
        } catch (AturanDilanggar) {
        }
        $this->svc()->formulirLunas($p, 250_000, $tu);
        $hasil = $this->svc()->terima($p->fresh(), Kelas::where('nama', '1 PUTRA')->first(), '272801', $this->tgl('2027-12-31'), $tu);

        $this->assertSame(StatusSantri::Calon, $hasil['santri']->status);
        $this->assertNotNull($hasil['santri']->kode_unik);
        $this->assertTrue($hasil['akun_baru']);
        $this->assertTrue($hasil['wali']->adalahWaliDari($hasil['santri']));
        $this->assertSame(5_000_000, $hasil['santri']->tagihan()->first()->nominal);
        $this->assertSame(StatusPendaftaran::Diterima, $p->fresh()->status);

        (new DataSantriService())->aktifkan($hasil['santri'], $this->tgl('2027-07-12'), $tu);
        $this->assertSame(StatusSantri::Aktif, $hasil['santri']->fresh()->status);
    }

    public function test_adik_dengan_nomor_wali_sama_ditautkan_ke_akun_wali_yang_ada(): void
    {
        $ta = TahunAjaran::untukTanggal($this->tgl('2027-07-01'));
        $tu = $this->tu();
        $kelas = Kelas::where('nama', '1 PUTRA')->first();
        $a = $this->svc()->daftar($this->formulir('Kakak', '081299990000'), $ta);
        $b = $this->svc()->daftar($this->formulir('Adik', '6281299990000'), $ta);
        foreach ([[$a, '272811'], [$b, '272812']] as [$p, $nis]) {
            $this->svc()->formulirLunas($p, 250_000, $tu);
        }
        $h1 = $this->svc()->terima($a->fresh(), $kelas, '272811', $this->tgl('2027-12-31'), $tu);
        $h1['wali']->update(['aktif' => false]); // kakak sudah lulus, akun wali sempat dinonaktifkan
        $h2 = $this->svc()->terima($b->fresh(), $kelas, '272812', $this->tgl('2027-12-31'), $tu);
        $this->assertTrue($h1['wali']->fresh()->aktif, 'akun wali lama aktif lagi saat adiknya ditautkan');
        $this->assertFalse($h2['akun_baru']);
        $this->assertSame($h1['wali']->id, $h2['wali']->id);
        $this->assertSame(2, $h1['wali']->anak()->count());
    }

    public function test_perubahan_nomor_whatsapp_wali_menunggu_verifikasi(): void
    {
        $wali = $this->buatUser('Wali');
        $wali->update(['telepon' => '081100000001']);
        $svc = new DataWaliService();
        $svc->ubahProfil($wali, ['alamat' => 'Alamat baru', 'telepon' => '0811-0000-0002']);
        $this->assertSame('Alamat baru', $wali->fresh()->alamat, 'alamat langsung berubah');
        $this->assertSame('081100000001', $wali->fresh()->telepon, 'nomor lama tetap dipakai');
        $this->assertSame('081100000002', $wali->fresh()->telepon_menunggu);

        $svc->setujuiTelepon($wali->fresh(), $this->tu());
        $this->assertSame('081100000002', $wali->fresh()->telepon);
    }

    public function test_form_tambah_wali_membuat_akun_atau_menautkan_dan_minimal_satu_wali(): void
    {
        $tu = $this->beriIzin($this->buatUser('TU'), Izin::SantriKelola, Izin::PendaftaranProses);
        $kakak = $this->santri();
        $adik = $this->santri();
        $svc = new DataWaliService();

        $ayah = $svc->tambahAtauTautkan(['name' => 'Hasan', 'telepon' => '0812-7000-0001'], [$kakak], 'ayah', $tu);
        $this->assertTrue($ayah['akun_baru']);
        $this->assertNotNull($ayah['password_awal']);
        $this->assertTrue($ayah['wali']->hasRole('wali_santri'));

        $lagi = $svc->tambahAtauTautkan(['name' => 'Hasan', 'telepon' => '6281270000001'], [$adik], 'ayah', $tu);
        $this->assertFalse($lagi['akun_baru'], 'nomor sama -> ditautkan');
        $this->assertSame(2, $ayah['wali']->anak()->count());

        try {
            $svc->lepasTautan($ayah['wali'], $adik, $tu);
            $this->fail('santri harus punya minimal satu wali');
        } catch (AturanDilanggar) {
        }
        $svc->tambahAtauTautkan(['name' => 'Aminah', 'telepon' => '081270000002'], [$adik], 'ibu', $tu);
        $svc->lepasTautan($ayah['wali'], $adik, $tu);
        $this->assertSame(1, $ayah['wali']->anak()->count());
    }
}
