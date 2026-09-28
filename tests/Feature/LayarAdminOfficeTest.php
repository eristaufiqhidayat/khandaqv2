<?php

namespace Tests\Feature;

use App\Contracts\WaGateway;
use App\Enums\StatusPendaftaran;
use App\Enums\StatusSantri;
use App\Models\Kelas;
use App\Models\Pendaftaran;
use App\Models\Raport;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaPesan;
use App\Services\AkunService;
use App\Services\Whatsapp\PengirimWa;
use App\Whatsapp\LogGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\KhandaqTestCase;

/** Layar Admin Office: Data santri & wali, Pendaftaran santri baru, Raport & dispensasi. */
class LayarAdminOfficeTest extends KhandaqTestCase
{
    private LogGateway $wa;

    protected function setUp(): void
    {
        parent::setUp();
        // Gateway WhatsApp tiruan yang "aktif" (bukan vendor log), agar password benar-benar dikirim.
        $this->wa = new class extends LogGateway {
            public function nama(): string { return 'ngirimwa'; }
        };
        $this->app->instance(WaGateway::class, $this->wa);
        $this->app->instance(PengirimWa::class, new PengirimWa($this->wa));
        TahunAjaran::untukTanggal(CarbonImmutable::now())->update(['aktif' => true]);
    }

    private function staf(string $peran, string $username): User
    {
        return User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@test.local",
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole($peran);
    }

    public function test_tambah_santri_lalu_wali_baru_mendapat_password_lewat_whatsapp_saja(): void
    {
        $this->actingAs($this->staf('admin_office', 'office'));
        $this->post(route('santri.store'), ['nis' => 'P-001', 'nama' => 'Hana Salsabila', 'jenis_kelamin' => 'perempuan',
            'kelas_id' => Kelas::where('nama', '1 PUTRI')->value('id')])->assertSessionHas('status');
        $s = Santri::where('nis', 'P-001')->firstOrFail();
        $this->assertNotNull($s->kode_unik);

        $r = $this->post(route('santri.wali.store', $s), ['name' => 'Ibu Rahma', 'telepon' => '0812-7777-8888', 'hubungan' => 'ibu']);
        $r->assertRedirect(route('santri.show', $s));
        $this->assertStringContainsString('dikirim ke WhatsApp 081277778888', session('status'));

        // Password ada di pesan WhatsApp, tetapi tidak di layar petugas dan tidak di log wa_pesan.
        $terkirim = $this->wa->terkirim[0]['isi'];
        preg_match('/Password sementara: (\S+)/', $terkirim, $m);
        $this->assertNotEmpty($m[1]);
        $this->assertStringNotContainsString($m[1], session('status'));
        $this->assertStringNotContainsString($m[1], WaPesan::latest('id')->value('isi'));
        $wali = User::where('telepon', '081277778888')->firstOrFail();
        $this->assertTrue(password_verify($m[1], $wali->password));
        $this->assertTrue($wali->hasRole('wali_santri'));

        $this->get(route('santri.show', $s))->assertOk()->assertSee('Ibu Rahma')->assertSee('081277778888');
        $this->get(route('santri.index', ['q' => 'Hana']))->assertOk()->assertSee('Hana Salsabila');
    }

    public function test_adik_ditautkan_ke_akun_wali_yang_ada_dan_reset_password(): void
    {
        [$kakak, $adik] = [$this->santri(), $this->santri()];
        $this->actingAs($this->staf('admin_office', 'office'));
        $this->post(route('santri.wali.store', $kakak), ['name' => 'Pak Hasan', 'telepon' => '081311112222', 'hubungan' => 'ayah']);
        $this->post(route('santri.wali.store', $adik), ['name' => 'Pak Hasan', 'telepon' => '+62 813-1111-2222', 'hubungan' => 'ayah'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'sudah punya akun'));
        $wali = User::where('telepon', '081311112222')->sole();
        $this->assertSame(2, $wali->anak()->count());
        $this->assertCount(1, $this->wa->terkirim, 'akun kedua tidak dibuat, password tidak dikirim ulang');

        $this->post(route('santri.wali.reset', [$adik, $wali]))->assertSessionHas('status', fn ($s) => str_contains($s, 'dikirim ke WhatsApp'));
        $this->assertCount(2, $this->wa->terkirim);
        $this->assertTrue((bool) $wali->fresh()->wajib_ganti_password);
    }

    public function test_tanpa_whatsapp_password_tidak_ditampilkan(): void
    {
        $this->app->instance(PengirimWa::class, new PengirimWa(new LogGateway()));
        $s = $this->santri();
        $this->actingAs($this->staf('admin_office', 'office'));
        $this->post(route('santri.wali.store', $s), ['name' => 'Ibu Siti', 'telepon' => '081399990000', 'hubungan' => 'ibu'])
            ->assertSessionHas('status', fn ($st) => str_contains($st, 'WhatsApp belum diatur') && ! preg_match('/Password sementara: \S+/', $st));
    }

    public function test_verifikasi_nomor_wa_wali(): void
    {
        $s = $this->santri();
        $wali = $this->wali($s);
        $wali->update(['telepon' => '081200000001', 'telepon_menunggu' => '081200000002']);
        $this->actingAs($this->staf('admin_office', 'office'));
        $this->get(route('santri.index'))->assertOk()->assertSee('081200000002');
        $this->post(route('wali.telepon.setujui', $wali))->assertSessionHas('status');
        $this->assertSame('081200000002', $wali->fresh()->telepon);
    }

    public function test_pendaftaran_dari_kantor_sampai_diterima(): void
    {
        $this->actingAs($this->staf('admin_office', 'office'));
        $this->post(route('pendaftaran.store'), ['nama_calon' => 'Umar Faruq', 'jenis_kelamin' => 'laki-laki', 'tingkat_tujuan' => 1,
            'nama_wali' => 'Pak Umar', 'hubungan_wali' => 'ayah', 'telepon_wali' => '081355556666', 'alamat' => 'Bogor'])->assertSessionHas('status');
        $p = Pendaftaran::sole();
        $this->get(route('pendaftaran.index'))->assertOk()->assertSee('Umar Faruq');

        $this->post(route('pendaftaran.lunas', $p), ['biaya' => 250000])->assertSessionHas('status');
        // Tarif DSB tahun ajaran tujuan sudah diatur Admin.
        \App\Models\Tarif::create(['jenis_tagihan_id' => \App\Models\JenisTagihan::kode('DSB')->id, 'tahun_ajaran_id' => $p->tahun_ajaran_id,
            'nominal' => 5_000_000, 'berlaku_mulai' => $p->tahunAjaran->mulai]);
        $this->post(route('pendaftaran.terima', $p), ['kelas_id' => Kelas::where('nama', '1 PUTRA')->value('id'), 'nis' => 'B-2027-01',
            'jatuh_tempo_dsb' => now()->addDays(30)->toDateString()])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Tagihan DSB Rp5.000.000 dibuat') && str_contains($s, 'dikirim ke WhatsApp'));

        $this->assertSame(StatusPendaftaran::Diterima, $p->fresh()->status);
        $santri = Santri::where('nis', 'B-2027-01')->sole();
        $this->assertSame(StatusSantri::Calon, $santri->status);
        $this->assertSame(1, $santri->tagihan()->count(), 'tagihan DSB');

        $this->post(route('santri.aktifkan', $santri), ['tanggal_masuk' => now()->toDateString()])->assertSessionHas('status');
        $this->assertSame(StatusSantri::Aktif, $santri->fresh()->status);
    }

    public function test_terima_pendaftaran_tanpa_tarif_dsb_memberi_peringatan(): void
    {
        $this->actingAs($this->staf('admin_office', 'office'));
        $p = app(\App\Services\PendaftaranService::class)->daftar(['nama_calon' => 'Zaid', 'jenis_kelamin' => 'laki-laki', 'nama_wali' => 'Pak Zaid',
            'telepon_wali' => '081344445555', 'alamat' => 'Depok'], TahunAjaran::untukTanggal(CarbonImmutable::create(2030, 7, 1)));
        $this->post(route('pendaftaran.lunas', $p), ['biaya' => 0]);
        $this->post(route('pendaftaran.terima', $p), ['kelas_id' => Kelas::where('nama', '1 PUTRA')->value('id'), 'nis' => 'Z-01',
            'jatuh_tempo_dsb' => now()->addDays(30)->toDateString()])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'tarif DSB 2030/2031 belum diatur'));
    }

    public function test_raport_diunggah_diterbitkan_dan_wali_hanya_bisa_membuka_milik_anaknya(): void
    {
        Storage::fake();
        $s = $this->santri();
        $semester = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1))->semester()->where('nomor', 1)->first();
        $wali = tap($this->wali($s))->update(['wajib_ganti_password' => false]);
        $walLain = tap($this->wali($this->santri()))->update(['wajib_ganti_password' => false]);

        $this->actingAs($this->staf('admin_office', 'office'));
        $this->get(route('raport.index', ['semester' => $semester->id]))->assertOk()->assertSee($s->nama);
        $this->post(route('raport.unggah', $s), ['semester_id' => $semester->id, 'jenis' => 'pts',
            'file' => UploadedFile::fake()->create('r.pdf', 200, 'application/pdf'), 'terbitkan' => 1])->assertSessionHas('status');
        $r = Raport::sole();
        $this->assertNotNull($r->diterbitkan_pada);
        $this->get(route('raport.lihat', $r))->assertOk();

        $this->actingAs($wali)->get(route('raport.lihat', $r))->assertOk();
        $this->actingAs($walLain)->get(route('raport.lihat', $r))->assertForbidden();
    }

    public function test_raport_tertahan_bisa_diajukan_dispensasi(): void
    {
        $s = $this->santri();
        $this->generator()->bulanan($this->tgl('2025-07-01'));
        $semester = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1))->semester()->where('nomor', 1)->first();
        $this->actingAs($this->staf('admin_office', 'office'));
        $this->get(route('raport.index', ['semester' => $semester->id]))->assertOk()->assertSee('1 tertahan')->assertSee('Ajukan dispensasi');
        $this->post(route('raport.dispensasi', $s), ['semester_id' => $semester->id, 'janji_bayar' => now()->addWeek()->toDateString(), 'alasan' => 'Menunggu panen'])
            ->assertSessionHas('status');
        $this->assertDatabaseHas('keringanan', ['santri_id' => $s->id, 'jenis' => 'dispensasi_raport', 'status' => 'diajukan']);
    }

    public function test_layar_admin_office_tertutup_untuk_keuangan(): void
    {
        $this->actingAs($this->staf('keuangan', 'keu'));
        foreach (['santri.index', 'pendaftaran.index', 'raport.index'] as $r) {
            $this->get(route($r))->assertForbidden();
        }
    }
}
