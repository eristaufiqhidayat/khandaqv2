<?php

namespace Tests\Feature;

use App\Models\Pegawai;
use App\Models\Penggajian;
use App\Models\User;
use App\Services\AkunService;
use App\Services\PayrollBsi;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\KhandaqTestCase;

class GajiPegawaiTest extends KhandaqTestCase
{
    /** Berkas contoh dengan format persis berkas payroll BSI (data fiktif). */
    private const BERKAS_LAMA = "0||2026-08-31|2|6100000|\n"
        .PayrollBsi::JUDUL_KOLOM."\n"
        ."1|8092949010|Guru Satu|IDR|4510000|123|Gaji Agustus 2026|2|guru1@contoh.id|08128815569|\n"
        ."2|7210291258|Guru Dua|IDR|1590000|123|Gaji Agustus 2026|2||0857 8092 7108|";

    private function staf(string $peran = 'keuangan'): User
    {
        return User::firstWhere('username', $peran) ?? User::create(['name' => ucfirst($peran), 'username' => $peran, 'email' => "{$peran}@test.local",
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole($peran);
    }

    private function keu(): User
    {
        return $this->staf();
    }

    public function test_impor_berkas_lama_mengisi_data_pegawai_dan_aman_diulang(): void
    {
        $svc = app(PayrollBsi::class);
        $this->assertSame(['baru' => 2, 'diperbarui' => 0], $svc->imporPegawai(self::BERKAS_LAMA));
        $g = Pegawai::where('no_rekening', '8092949010')->first();
        $this->assertSame(['Guru Satu', 'Guru Satu', 4_510_000, 'guru1@contoh.id', '08128815569'], [$g->nama, $g->nama_rekening, $g->nominal_tetap, $g->email, $g->telepon]);
        $this->assertSame('085780927108', Pegawai::where('no_rekening', '7210291258')->value('telepon'));

        $this->assertSame(['baru' => 0, 'diperbarui' => 2], $svc->imporPegawai(self::BERKAS_LAMA));
        $this->assertSame(2, Pegawai::count());
    }

    public function test_berkas_payroll_persis_format_bsi_dengan_header_terhitung(): void
    {
        $svc = app(PayrollBsi::class);
        $svc->imporPegawai(self::BERKAS_LAMA);
        Pegawai::where('no_rekening', '7210291258')->update(['nama' => 'Guru | Dua', 'nama_rekening' => "Guru\nDua"]);
        $p = $svc->buat(CarbonImmutable::create(2026, 9), CarbonImmutable::parse('2026-09-30'), 'Gaji September 2026', $this->keu());
        $p->rincian()->where('pegawai_id', Pegawai::where('no_rekening', '7210291258')->value('id'))->update(['nominal' => 1_600_000, 'pesan' => 'Gaji Sept + honor rapat']);

        $this->assertSame(
            "0||2026-09-30|2|6110000|\n"
            ."0|BENEFICIARY ACCT (35)|BENEFICIARY ACCT NAME |CREDIT AMOUNT CCY|AMOUNT|CUST REF NO|MESSAGE (65)|EXTENDED PAYMENT DETAIL|BENEFICIARY NOTIF EMAIL(100)|SMS NOTIF (100)|\n"
            ."1|8092949010|Guru Satu|IDR|4510000|123|Gaji September 2026|2|guru1@contoh.id|08128815569|\n"
            ."2|7210291258|Guru Dua|IDR|1600000|123|Gaji Sept + honor rapat|2||085780927108|",
            $svc->berkas($p), 'nominal dari penggajian sebelumnya tidak ada, jadi nominal tetap; pemisah | dan baris baru dibuang');
        $this->assertSame('gaji_30-09-2026.txt', $p->namaFile());
    }

    public function test_nominal_bulan_berikutnya_mengikuti_penggajian_sebelumnya_dan_final_mengunci(): void
    {
        $svc = app(PayrollBsi::class);
        $svc->imporPegawai(self::BERKAS_LAMA);
        $sep = $svc->buat(CarbonImmutable::create(2026, 9), CarbonImmutable::parse('2026-09-30'), 'Gaji September 2026', $this->keu());
        $sep->rincian()->first()->update(['nominal' => 4_600_000]);
        $svc->finalkan($sep, $this->keu2());
        $berkasSep = $svc->berkas($sep->refresh());

        Pegawai::where('no_rekening', '8092949010')->update(['no_rekening' => '8092949099']); // rekening diganti
        $okt = $svc->buat(CarbonImmutable::create(2026, 10), CarbonImmutable::parse('2026-10-30'), 'Gaji Oktober 2026', $this->keu());
        $this->assertSame(4_600_000, $okt->rincian()->first()->nominal);
        $this->assertStringContainsString('|8092949099|', $svc->berkas($okt));
        $this->assertSame($berkasSep, $svc->berkas($sep->refresh()), 'berkas penggajian final tidak berubah');
    }

    public function test_data_salah_menahan_unduhan(): void
    {
        Pegawai::create(['nama' => 'Tanpa Rekening', 'no_rekening' => '123', 'nama_rekening' => 'Tanpa Rekening', 'nominal_tetap' => 0]);
        $keu = $this->keu();
        $p = app(PayrollBsi::class)->buat(CarbonImmutable::create(2026, 9), CarbonImmutable::parse('2026-09-30'), '', $keu);
        $galat = app(PayrollBsi::class)->periksa($p);
        $this->assertCount(2, reset($galat));

        $this->actingAs($keu)->get(route('gaji.unduh', $p))->assertRedirect(route('gaji.show', $p));
        $this->actingAs($keu)->get(route('gaji.show', $p))->assertOk()->assertSee('Nomor rekening tidak valid')->assertSee('Nominal belum diisi');
    }

    public function test_alur_layar_impor_buat_ubah_unduh(): void
    {
        $keu = $this->keu();
        $this->actingAs($keu)->post(route('gaji.pegawai.impor'), ['berkas' => UploadedFile::fake()->createWithContent('gaji_01-09-2026.txt', self::BERKAS_LAMA)])
            ->assertRedirect(route('gaji.pegawai'))->assertSessionHas('status', '2 pegawai baru, 0 diperbarui dari berkas. Lengkapi nama lengkap & jabatan bila perlu.');
        $this->get(route('gaji.pegawai'))->assertOk()->assertSee('Guru Satu')->assertSee('7210291258');

        $this->post(route('gaji.store'), ['periode' => '2026-09', 'tanggal_transfer' => '2026-09-30', 'pesan' => '', 'sumber' => 'sebelumnya'])->assertRedirect();
        $p = Penggajian::firstOrFail();
        $this->assertSame('Gaji September 2026', $p->pesan);
        [$r1, $r2] = $p->rincian->all();
        $this->put(route('gaji.update', $p), ['judul' => 'Gaji September 2026', 'tanggal_transfer' => '2026-09-30', 'pesan' => 'Gaji September 2026',
            'rincian' => [$r1->id => ['nominal' => 5_000_000, 'pesan' => ''], $r2->id => ['nominal' => 1, 'hapus' => 1]]])->assertRedirect(route('gaji.show', $p));
        $this->assertSame(1, $p->rincian()->count());

        $this->get(route('gaji.unduh', $p))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="gaji_30-09-2026.txt"')
            ->assertSeeText('0||2026-09-30|1|5000000|', false);
        $this->post(route('gaji.final', $p))->assertRedirect(route('gaji.show', $p));
        $this->assertTrue($p->refresh()->final());
        $this->put(route('gaji.update', $p), ['judul' => 'x', 'tanggal_transfer' => '2026-09-30', 'pesan' => 'x'])->assertForbidden();
    }

    public function test_hanya_pemegang_izin_gaji(): void
    {
        $this->actingAs($this->staf('admin_office'))->get(route('gaji.index'))->assertForbidden();
        $this->actingAs($this->staf('keuangan'))->get(route('gaji.index'))->assertOk()->assertSee('Buat penggajian');
    }

    private function keu2(): User
    {
        return $this->beriIzin($this->buatUser('Keu 2'), \App\Enums\Izin::GajiKelola);
    }
}
