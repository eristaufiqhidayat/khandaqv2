<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AkunService;
use Illuminate\Support\Facades\Artisan;
use Tests\KhandaqTestCase;

class UbahWaliTest extends KhandaqTestCase
{
    private function staf(string $peran, string $username): User
    {
        return User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@test.local",
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole($peran);
    }

    private function waliAkun(string $telepon, ...$anak): User
    {
        $w = $this->wali(...$anak)->assignRole('wali_santri');
        $w->update(['name' => 'Wali '.$telepon, 'username' => $telepon, 'telepon' => $telepon, 'wajib_ganti_password' => false]);

        return $w;
    }

    public function test_petugas_mengubah_data_wali(): void
    {
        [$a, $b] = [$this->santri(), $this->santri()];
        $w = $this->waliAkun('081200000001', $a, $b);
        $lain = $this->waliAkun('081200000009', $this->santri());
        $office = $this->staf('admin_office', 'office');

        $this->actingAs($office)->get(route('santri.show', $a))->assertSee(route('walisantri.edit', ['wali' => $w, 'kembali' => route('santri.show', $a)]), false);
        $this->get(route('walisantri.edit', ['wali' => $w, 'kembali' => route('santri.show', $a)]))->assertOk()->assertSee($b->nama);

        $this->put(route('walisantri.update', $w), ['name' => 'Bapak Budi', 'telepon' => '0812-0000-0002', 'email' => 'budi@contoh.test',
            'pekerjaan' => 'Guru', 'alamat' => 'Jl. Wali 1', 'nik' => '', 'hubungan' => [$a->id => 'ayah', $b->id => 'ayah'],
            'kembali' => route('santri.show', $a)])->assertRedirect(route('santri.show', $a));
        $w->refresh();
        $this->assertSame(['Bapak Budi', '081200000002', 'budi@contoh.test', 'Guru', 'Jl. Wali 1', '081200000001'],
            [$w->name, $w->telepon, $w->email, $w->pekerjaan, $w->alamat, $w->username], 'username tetap');
        $this->assertSame(['ayah', 'ayah'], $w->anak()->orderBy('santri.id')->get()->pluck('pivot.hubungan')->all());

        // Nomor milik akun lain ditolak.
        $this->put(route('walisantri.update', $w), ['name' => 'Bapak Budi', 'telepon' => $lain->telepon])->assertSessionHasErrors('wali');
        $this->assertSame('081200000002', $w->fresh()->telepon);
        // Redirect hanya ke URL aplikasi sendiri.
        $this->put(route('walisantri.update', $w), ['name' => 'Bapak Budi', 'telepon' => '081200000002', 'kembali' => 'https://jahat.test/'])
            ->assertRedirect(route('pengguna.index', ['tab' => 'wali']));
        // Akun staf tidak bisa diubah lewat form wali; keuangan tidak punya izin.
        $this->get(route('walisantri.edit', $office))->assertNotFound();
        $this->actingAs($this->staf('keuangan', 'keu'))->get(route('walisantri.edit', $w))->assertForbidden();
    }

    public function test_wali_mengubah_profil_sendiri_nomor_menunggu_verifikasi(): void
    {
        $w = $this->waliAkun('081200000003', $this->santri());
        $this->actingAs($w)->get(route('wali.beranda'))->assertSee(route('wali.profil'), false);
        $this->get(route('wali.profil'))->assertOk();
        $this->put(route('wali.profil.update'), ['alamat' => 'Alamat wali baru', 'pekerjaan' => 'Petani', 'email' => 'wali3@contoh.test', 'telepon' => '081200000004'])
            ->assertRedirect(route('wali.data'))->assertSessionHas('status');
        $w->refresh();
        $this->assertSame(['Alamat wali baru', 'Petani', 'wali3@contoh.test', '081200000003', '081200000004'],
            [$w->alamat, $w->pekerjaan, $w->email, $w->telepon, $w->telepon_menunggu]);
        $this->put(route('wali.profil.update'), ['name' => 'Ganti nama', 'alamat' => 'x', 'telepon' => '081200000003']);
        $this->assertNotSame('Ganti nama', $w->fresh()->name, 'nama tidak bisa diubah wali');
        $this->assertNull($w->fresh()->telepon_menunggu, 'kembali ke nomor lama membatalkan pengajuan');
        $this->actingAs($this->staf('admin_office', 'office'))->get(route('wali.profil'))->assertForbidden();
    }

    public function test_perintah_wali_contoh(): void
    {
        $s = $this->santri();
        $this->assertSame(0, Artisan::call('khandaq:wali-contoh', ['--santri' => [$s->nis]]));
        $u = User::where('username', 'walicontoh')->sole();
        $this->assertTrue($u->hasRole('wali_santri'));
        $this->assertTrue($u->adalahWaliDari($s));
        preg_match('/Password\s*\|\s*(\S+)/', Artisan::output(), $m);
        $this->post(route('login'), ['username' => 'walicontoh', 'password' => $m[1]])->assertRedirect();
        $this->get(route('wali.beranda'))->assertOk()->assertSee($s->nama);

        $this->assertSame(0, Artisan::call('khandaq:wali-contoh')); // dijalankan ulang: tidak ganda
        $this->assertSame(1, User::where('username', 'walicontoh')->count());
        Artisan::call('khandaq:wali-contoh', ['--hapus' => true]);
        $this->assertSame(0, User::where('username', 'walicontoh')->count());
    }

    public function test_petugas_mengganti_password_wali(): void
    {
        $w = $this->waliAkun('081200000011', $this->santri());
        [, $token] = \App\Models\ApiToken::terbitkan($w, 'HP');
        $admin = $this->staf('admin', 'admin');

        $this->actingAs($admin)->get(route('walisantri.edit', $w))->assertOk()->assertSee('Ganti password wali');
        $this->post(route('walisantri.password', $w), ['password' => 'pendek', 'password_confirmation' => 'pendek'])->assertSessionHasErrors('password');
        $this->post(route('walisantri.password', $w), ['password' => 'barubaru1', 'password_confirmation' => 'lain12345'])->assertSessionHasErrors('password');
        $this->post(route('walisantri.password', $w), ['password' => 'barubaru1', 'password_confirmation' => 'barubaru1', 'wajib_ganti' => '1',
            'kembali' => route('pengguna.index', ['tab' => 'wali'])])->assertRedirect(route('pengguna.index', ['tab' => 'wali']));
        $w->refresh();
        $this->assertTrue(password_verify('barubaru1', $w->password));
        $this->assertTrue($w->wajib_ganti_password);
        $this->assertSame(0, \App\Models\ApiToken::where('user_id', $w->id)->count(), 'sesi aplikasi dicabut');

        // Admin Office juga boleh; keuangan tidak; akun staf tidak bisa lewat form wali.
        $this->actingAs($this->staf('admin_office', 'office'))->post(route('walisantri.password', $w),
            ['password' => 'lagibaru12', 'password_confirmation' => 'lagibaru12'])->assertSessionHas('status');
        $this->assertFalse($w->fresh()->wajib_ganti_password);
        $this->post(route('walisantri.password', $admin), ['password' => 'lagibaru12', 'password_confirmation' => 'lagibaru12'])->assertNotFound();
        $this->actingAs($this->staf('keuangan', 'keu'))->post(route('walisantri.password', $w),
            ['password' => 'lagibaru12', 'password_confirmation' => 'lagibaru12'])->assertForbidden();
    }
}
