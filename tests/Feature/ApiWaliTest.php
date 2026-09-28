<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\KalenderAkademik;
use App\Models\Raport;
use App\Models\TabunganMutasi;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\AkunService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\KhandaqTestCase;

/** API aplikasi Android wali (/api/v1). */
class ApiWaliTest extends KhandaqTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function waliAkun(string $telepon, string $password, ...$anak): User
    {
        $w = $this->wali(...$anak)->assignRole('wali_santri');
        $w->update(['name' => 'Wali '.$telepon, 'username' => $telepon, 'telepon' => $telepon,
            'password' => AkunService::hash($password), 'wajib_ganti_password' => false]);

        return $w;
    }

    private function masuk(string $username, string $password): string
    {
        return $this->postJson('/api/v1/masuk', ['username' => $username, 'password' => $password, 'perangkat' => 'Samsung A15'])
            ->assertOk()->json('token');
    }

    public function test_masuk_token_dan_hanya_anak_sendiri(): void
    {
        CarbonImmutable::setTestNow('2025-10-15 09:00');
        TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1))->update(['aktif' => true]);
        [$a, $b, $lain] = [$this->santri('3 PUTRA'), $this->santri('1 PUTRA'), $this->santri()];
        $w = $this->waliAkun('081300000001', 'rahasia123', $a, $b);
        $this->waliAkun('081300000002', 'rahasia123', $lain);

        $this->postJson('/api/v1/masuk', ['username' => '081300000001', 'password' => 'salah'])->assertStatus(422)->assertJson(['kode' => 'salah']);
        $r = $this->postJson('/api/v1/masuk', ['username' => '+62 813-0000-0001', 'password' => 'rahasia123', 'perangkat' => 'HP Ayah'])
            ->assertOk()->assertJsonPath('wali.nama', 'Wali 081300000001')->assertJsonCount(2, 'anak')->assertJsonPath('anak.0.kelas', '3 PUTRA');
        $token = $r->json('token');
        $this->assertStringContainsString('|', $token);
        $this->assertNotSame($token, ApiToken::sole()->token_hash, 'yang disimpan hanya hash');

        $this->getJson('/api/v1/saya')->assertStatus(401)->assertJson(['kode' => 'token_tidak_sah']);
        $h = ['Authorization' => "Bearer {$token}"];
        $this->getJson('/api/v1/saya', $h)->assertOk()->assertJsonPath('wali.telepon', '081300000001');
        $this->getJson("/api/v1/anak/{$a->id}", $h)->assertOk()->assertJsonPath('santri.nis', $a->nis);
        $this->getJson("/api/v1/anak/{$lain->id}", $h)->assertNotFound();
        $this->getJson("/api/v1/anak/{$lain->id}/tabungan", $h)->assertNotFound();
        $this->getJson('/api/v1/saya', ['Authorization' => 'Bearer 1|palsu'])->assertStatus(401);

        // Akun dinonaktifkan: token langsung tidak berlaku.
        $w->update(['aktif' => false]);
        $this->getJson('/api/v1/saya', $h)->assertStatus(401)->assertJson(['kode' => 'akun_nonaktif']);
        $this->assertSame(0, ApiToken::count());
    }

    public function test_staf_tidak_bisa_masuk_aplikasi_dan_token_kedaluwarsa(): void
    {
        User::create(['name' => 'Office', 'username' => 'office', 'email' => 'o@test.local', 'password' => AkunService::hash('rahasia123'),
            'wajib_ganti_password' => false])->assignRole('admin_office');
        $this->postJson('/api/v1/masuk', ['username' => 'office', 'password' => 'rahasia123'])->assertForbidden()->assertJson(['kode' => 'bukan_wali']);

        $this->waliAkun('081300000003', 'rahasia123', $this->santri());
        $token = $this->masuk('081300000003', 'rahasia123');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(ApiToken::HARI_BERLAKU + 1));
        $this->getJson('/api/v1/saya', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_tabungan_raport_kalender_dan_keluar(): void
    {
        CarbonImmutable::setTestNow('2025-12-21 09:00');
        Storage::fake('local');
        $ta = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1));
        $ta->update(['aktif' => true]);
        $s = $this->santri('3 PUTRA');
        $this->waliAkun('081300000004', 'rahasia123', $s);
        $this->tabungan()->catatSetoran($s, 500_000, $this->tgl('2025-10-01'), false, $this->adminOffice());
        $raport = Raport::create(['santri_id' => $s->id, 'semester_id' => $ta->semesterUntuk($this->tgl('2025-12-01'))->id, 'jenis' => 'pas',
            'file_path' => 'raport/uji.pdf', 'diterbitkan_pada' => '2025-12-20 08:00']);
        Storage::put('raport/uji.pdf', '%PDF-1.4 uji');
        KalenderAkademik::create(['tanggal_mulai' => '2025-12-22', 'tanggal_selesai' => '2026-01-04', 'kegiatan' => 'Libur semester']);
        $h = ['Authorization' => 'Bearer '.$this->masuk('081300000004', 'rahasia123')];

        $this->getJson("/api/v1/anak/{$s->id}/tabungan", $h)->assertOk()
            ->assertJsonPath('saldo', $s->saldo())->assertJsonFragment(['jenis_label' => 'Setoran tunai'])
            ->assertJsonPath('kode_transfer', $s->kode_unik)->assertJsonStructure(['tagihan', 'rekening_transfer', 'mutasi' => ['halaman', 'halaman_terakhir']]);

        $url = $this->getJson("/api/v1/anak/{$s->id}/raport", $h)->assertOk()->assertJsonPath('raport.0.terkunci', false)->json('raport.0.pdf_url');
        $this->get($url, $h)->assertOk()->assertHeader('content-type', 'application/pdf');

        // SPP November belum dibayar dan saldo tidak cukup -> raport tertahan.
        $this->generator()->bulanan($this->tgl('2025-11-01'));
        TabunganMutasi::query()->delete();
        $this->getJson("/api/v1/anak/{$s->id}/raport", $h)->assertJsonPath('raport.0.terkunci', true)->assertJsonPath('raport.0.pdf_url', null);
        $this->getJson("/api/v1/anak/{$s->id}/raport/{$raport->id}/pdf", $h)->assertForbidden()->assertJson(['kode' => 'raport_tertahan']);

        $this->getJson('/api/v1/kalender', $h)->assertOk()->assertJsonPath('kegiatan.0.kegiatan', 'Libur semester');
        $this->postJson('/api/v1/keluar', [], $h)->assertOk();
        $this->getJson('/api/v1/saya', $h)->assertStatus(401);
    }

    public function test_lapor_transfer_dengan_bukti_masuk_verifikasi(): void
    {
        Storage::fake('local');
        $s = $this->santri();
        $this->waliAkun('081300000005', 'rahasia123', $s);
        $h = ['Authorization' => 'Bearer '.$this->masuk('081300000005', 'rahasia123')];

        $this->postJson("/api/v1/anak/{$s->id}/lapor-transfer", ['nominal' => 1_500_000, 'tanggal' => now()->toDateString()], $h)
            ->assertStatus(422)->assertJsonValidationErrors('bukti');
        $this->post("/api/v1/anak/{$s->id}/lapor-transfer", ['nominal' => 1_500_000, 'tanggal' => now()->toDateString(),
            'catatan' => 'SPP Oktober', 'bukti' => UploadedFile::fake()->image('bukti.jpg')], $h + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('mutasi.status', 'pending');
        $m = TabunganMutasi::sole();
        $this->assertSame(['setoran_transfer', 1_500_000, 'Lapor transfer wali: SPP Oktober'], [$m->jenis->value, (int) $m->nominal, $m->keterangan]);
        Storage::disk('local')->assertExists($m->bukti_path);
        $this->assertSame(0, $s->saldo(), 'saldo bertambah setelah diverifikasi');

        $office = User::create(['name' => 'Office', 'username' => 'office', 'email' => 'o@test.local', 'password' => AkunService::hash('rahasia123'),
            'wajib_ganti_password' => false])->assignRole('admin_office');
        $this->actingAs($office)->get(route('verifikasi.index'))->assertOk()->assertSee('1.500.000');
    }

    public function test_ubah_profil_dan_ganti_password_mencabut_perangkat_lain(): void
    {
        $this->waliAkun('081300000006', 'rahasia123', $this->santri());
        $hp1 = ['Authorization' => 'Bearer '.$this->masuk('081300000006', 'rahasia123')];
        $hp2 = ['Authorization' => 'Bearer '.$this->masuk('081300000006', 'rahasia123')];

        $this->putJson('/api/v1/saya', ['alamat' => 'Jl. Baru', 'pekerjaan' => 'Guru', 'telepon' => '081300000099'], $hp1)->assertOk()
            ->assertJsonPath('wali.alamat', 'Jl. Baru')->assertJsonPath('wali.telepon', '081300000006')->assertJsonPath('wali.telepon_menunggu_verifikasi', '081300000099');

        $this->postJson('/api/v1/saya/password', ['password_lama' => 'salah', 'password_baru' => 'baru12345', 'password_baru_confirmation' => 'baru12345'], $hp1)
            ->assertStatus(422);
        $this->postJson('/api/v1/saya/password', ['password_lama' => 'rahasia123', 'password_baru' => 'baru12345', 'password_baru_confirmation' => 'baru12345'], $hp1)
            ->assertOk();
        $this->getJson('/api/v1/saya', $hp1)->assertOk();
        $this->getJson('/api/v1/saya', $hp2)->assertStatus(401);
        $this->postJson('/api/v1/masuk', ['username' => '081300000006', 'password' => 'baru12345'])->assertOk();
    }
}
