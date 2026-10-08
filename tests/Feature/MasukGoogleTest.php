<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TokenFirebase;
use Tests\KhandaqTestCase;

class MasukGoogleTest extends KhandaqTestCase
{
    private \OpenSSLAsymmetricKey $kunci;

    private string $sertifikat = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['khandaq.firebase_project_id' => 'lembah-arafah']);
        $this->kunci = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'uji'], $this->kunci);
        openssl_x509_export(openssl_csr_sign($csr, null, $this->kunci, 1), $this->sertifikat);
        $this->app->bind(TokenFirebase::class, fn ($app, $p) => new TokenFirebase($p['projectId'], fn () => ['kid1' => $this->sertifikat]));
    }

    private function token(array $klaim = [], ?\OpenSSLAsymmetricKey $kunci = null): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $klaim += ['iss' => 'https://securetoken.google.com/lembah-arafah', 'aud' => 'lembah-arafah', 'sub' => 'uid-123',
            'iat' => time() - 10, 'exp' => time() + 3600, 'auth_time' => time() - 10,
            'email' => 'Ayah.Santri@gmail.com', 'email_verified' => true, 'firebase' => ['sign_in_provider' => 'google.com']];
        $isi = $b64(json_encode(['alg' => 'RS256', 'kid' => 'kid1', 'typ' => 'JWT'])).'.'.$b64(json_encode($klaim));
        openssl_sign($isi, $ttd, $kunci ?? $this->kunci, OPENSSL_ALGO_SHA256);

        return $isi.'.'.$b64($ttd);
    }

    private function waliDengan(string $email): User
    {
        $w = $this->wali($this->santri());
        $w->update(['email' => $email, 'wajib_ganti_password' => true]);

        return $w->assignRole('wali_santri');
    }

    public function test_masuk_dengan_google_lewat_email_wali_lalu_lewat_uid(): void
    {
        $w = $this->waliDengan('ayah.santri@gmail.com');
        $r = $this->postJson(route('api.v1.masuk.google'), ['id_token' => $this->token(), 'perangkat' => 'Pixel'])->assertOk();
        $r->assertJsonPath('wali.id', $w->id)->assertJsonPath('wali.wajib_ganti_password', false)->assertJsonPath('wali.google.terhubung', true);
        $this->assertCount(1, $r->json('anak'));
        $this->getJson(route('api.v1.saya'), ['Authorization' => 'Bearer '.$r->json('token')])->assertOk();
        $this->assertSame('uid-123', $w->fresh()->firebase_uid);

        $w->update(['email' => 'ganti@contoh.id']); // email di Khandaq berubah, tautan uid tetap
        $this->postJson(route('api.v1.masuk.google'), ['id_token' => $this->token()])->assertOk()->assertJsonPath('wali.id', $w->id);
    }

    public function test_token_palsu_kedaluwarsa_atau_project_lain_ditolak(): void
    {
        $this->waliDengan('ayah.santri@gmail.com');
        $lain = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        foreach ([
            $this->token([], $lain),
            $this->token(['exp' => time() - 3600]),
            $this->token(['aud' => 'project-lain', 'iss' => 'https://securetoken.google.com/project-lain']),
            $this->token(['email_verified' => false]),
            $this->token(['firebase' => ['sign_in_provider' => 'password']]),
            'bukan.jwt',
        ] as $t) {
            $this->postJson(route('api.v1.masuk.google'), ['id_token' => $t])->assertStatus(422);
        }
        $this->assertNull(User::whereNotNull('firebase_uid')->first());
    }

    public function test_email_belum_terdaftar_lalu_ditautkan_dari_profil(): void
    {
        $w = $this->waliDengan('lain@contoh.id');
        $this->postJson(route('api.v1.masuk.google'), ['id_token' => $this->token()])->assertStatus(404)->assertJsonPath('kode', 'google_belum_terhubung');

        [, $teks] = \App\Models\ApiToken::terbitkan($w, 'uji');
        $this->postJson(route('api.v1.saya.google'), ['id_token' => $this->token()], ['Authorization' => "Bearer {$teks}"])
            ->assertOk()->assertJsonPath('wali.google.email', 'ayah.santri@gmail.com');
        $this->postJson(route('api.v1.masuk.google'), ['id_token' => $this->token()])->assertOk()->assertJsonPath('wali.id', $w->id);

        $this->deleteJson(route('api.v1.saya.google'), [], ['Authorization' => "Bearer {$teks}"])->assertOk()->assertJsonPath('wali.google.terhubung', false);
    }

    public function test_staf_nonaktif_dan_server_belum_diatur(): void
    {
        $staf = $this->adminOffice();
        $staf->update(['email' => 'ayah.santri@gmail.com']);
        $this->postJson(route('api.v1.masuk.google'), ['id_token' => $this->token()])->assertStatus(404);

        $w = $this->waliDengan('x@contoh.id');
        $w->forceFill(['firebase_uid' => 'uid-123', 'aktif' => false])->save();
        $this->postJson(route('api.v1.masuk.google'), ['id_token' => $this->token()])->assertStatus(403)->assertJsonPath('kode', 'akun_nonaktif');

        config(['khandaq.firebase_project_id' => null]);
        $this->postJson(route('api.v1.masuk.google'), ['id_token' => $this->token()])->assertStatus(503);
    }
}
