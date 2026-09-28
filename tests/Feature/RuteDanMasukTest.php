<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AkunService;
use Tests\KhandaqTestCase;

/** Rute web: tamu diarahkan ke halaman masuk, pengguna diarahkan sesuai peran & izin. (Hanya di proyek Laravel.) */
class RuteDanMasukTest extends KhandaqTestCase
{
    private function akun(string $peran, string $username, string $password = 'rahasia123', bool $wajibGanti = false): User
    {
        $u = User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@test.local",
            'password' => AkunService::hash($password), 'wajib_ganti_password' => $wajibGanti, 'aktif' => true]);
        $u->assignRole($peran);

        return $u;
    }

    public function test_tamu_di_halaman_utama_diarahkan_ke_halaman_masuk(): void
    {
        $this->get('/')->assertRedirect('/masuk');
        $this->get('/masuk')->assertOk()->assertSee('Username, email, atau nomor WhatsApp');
        $this->get('/ringkasan')->assertRedirect('/masuk');
        $this->get('/wali')->assertRedirect('/masuk');
    }

    public function test_setelah_masuk_staf_ke_menu_pertamanya_dan_wali_ke_portal(): void
    {
        $this->akun('keuangan', 'manager');
        $this->akun('admin_office', 'office');
        $wali = $this->akun('wali_santri', '081234567890');
        $wali->update(['telepon' => '081234567890']);
        $wali->anak()->attach($this->santri()->id, ['hubungan' => 'ayah']);

        $this->post('/masuk', ['username' => 'manager', 'password' => 'rahasia123'])->assertRedirect('/beranda');
        $this->get('/beranda')->assertRedirect('/ringkasan');
        $this->get('/ringkasan')->assertOk()->assertSee('Ringkasan keuangan')->assertSee('Tutup buku');
        $this->get('/')->assertRedirect('/beranda');
        $this->post('/keluar')->assertRedirect('/masuk');

        $this->post('/masuk', ['username' => 'office', 'password' => 'rahasia123']);
        $this->get('/beranda')->assertRedirect('/kasir');
        $this->get('/ringkasan')->assertForbidden(); // Admin Office tidak punya laporan.lihat
        $this->get('/siaran')->assertOk()->assertSee('Siaran baru');
        $this->post('/keluar');

        // Wali masuk dengan nomor WhatsApp dalam format apa pun.
        $this->post('/masuk', ['username' => '+62 812-3456-7890', 'password' => 'rahasia123']);
        $this->get('/beranda')->assertRedirect('/wali');
        $this->get('/wali')->assertOk()->assertSee('Saldo tabungan');
        $this->get('/kasir')->assertForbidden();
    }

    public function test_password_salah_akun_nonaktif_dan_batas_percobaan(): void
    {
        $u = $this->akun('keuangan', 'manager');
        $this->post('/masuk', ['username' => 'manager', 'password' => 'salah'])->assertSessionHasErrors('username');
        $this->assertGuest();

        $u->update(['aktif' => false]);
        $this->post('/masuk', ['username' => 'manager', 'password' => 'rahasia123'])->assertSessionHasErrors(['username' => 'Akun dinonaktifkan. Hubungi Admin Office.']);
        $this->assertGuest();

        foreach (range(1, 4) as $_) {
            $this->post('/masuk', ['username' => 'manager', 'password' => 'salah']);
        }
        $this->post('/masuk', ['username' => 'manager', 'password' => 'salah'])->assertSessionHasErrors('username');
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('username'));
    }

    public function test_password_sementara_wajib_diganti_sebelum_membuka_halaman_lain(): void
    {
        $this->akun('keuangan', 'baru', 'Sementara1', wajibGanti: true);
        $this->post('/masuk', ['username' => 'baru', 'password' => 'Sementara1']);
        $this->get('/ringkasan')->assertRedirect('/ganti-password');
        $this->put('/ganti-password', ['password_lama' => 'Sementara1', 'password' => 'PasswordBaru9', 'password_confirmation' => 'PasswordBaru9'])
            ->assertRedirect('/beranda');
        $this->get('/ringkasan')->assertOk();
    }

    public function test_wali_masuk_dengan_password_aplikasi_lama(): void
    {
        $w = $this->akun('wali_santri', '081299990000');
        $w->update(['password' => AkunService::hash(AkunService::acak()),
            'password_lama' => password_hash(base64_encode(hash('sha384', 'passwordlama', true)), PASSWORD_DEFAULT)]);

        $this->post('/masuk', ['username' => '081299990000', 'password' => 'passwordlama'])->assertRedirect('/beranda');
        $this->assertAuthenticatedAs($w);
        $this->assertNull($w->fresh()->password_lama);
    }

    public function test_staf_tanpa_izin_apa_pun_melihat_halaman_tanpa_akses(): void
    {
        User::create(['name' => 'Kosong', 'username' => 'kosong', 'email' => 'k@test.local', 'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false]);
        $this->post('/masuk', ['username' => 'kosong', 'password' => 'rahasia123']);
        $this->get('/beranda')->assertForbidden()->assertSee('Belum ada akses');
    }

    public function test_menu_ikut_izin_dan_webhook_tanpa_login_maupun_csrf(): void
    {
        $this->akun('admin', 'admin');
        $this->post('/masuk', ['username' => 'admin', 'password' => 'rahasia123']);
        $html = $this->get('/tarif')->assertOk()->getContent();
        $this->assertStringContainsString('Sinkronisasi data lama', $html);
        $this->assertStringNotContainsString('>Kasir santri<', $html);

        config(['khandaq.whatsapp.meta.verify_token' => 'tok']);
        $this->get('/webhook/wa/meta?hub_mode=subscribe&hub_verify_token=tok&hub_challenge=42')->assertOk()->assertSee('42');
        $this->post('/webhook/wa/meta', ['entry' => []])->assertForbidden(); // tanpa tanda tangan
    }
}
