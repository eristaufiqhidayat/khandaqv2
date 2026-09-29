<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AkunService;
use Tests\KhandaqTestCase;

/** Admin mengubah data & password akun staf (WhatsApp belum aktif: gateway log). */
class UbahStafTest extends KhandaqTestCase
{
    private function staf(string $peran, string $username, ?string $telepon = null): User
    {
        return User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@test.local", 'telepon' => $telepon,
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole($peran);
    }

    public function test_admin_mengubah_data_dan_password_staf(): void
    {
        $admin = $this->staf('admin', 'eris');
        $danish = $this->staf('admin_office', 'danish', '08118684222');
        $lain = $this->staf('keuangan', 'keu', '081200001111');

        $this->actingAs($admin)->get(route('pengguna.index'))->assertSee(route('pengguna.edit', $danish), false);
        $this->get(route('pengguna.edit', $danish))->assertOk()->assertSee('Ganti password');

        $this->put(route('pengguna.update', $danish), ['name' => 'Danish Office', 'username' => 'danish_o', 'email' => 'danish@pondok.test', 'telepon' => '+62 811-8684-223'])
            ->assertRedirect(route('pengguna.index'));
        $danish->refresh();
        $this->assertSame(['Danish Office', 'danish_o', 'danish@pondok.test', '08118684223'], [$danish->name, $danish->username, $danish->email, $danish->telepon]);
        $this->put(route('pengguna.update', $danish), ['name' => 'X', 'username' => 'keu', 'email' => 'danish@pondok.test'])->assertSessionHasErrors('username');
        $this->put(route('pengguna.update', $danish), ['name' => 'X', 'username' => 'danish_o', 'email' => 'danish@pondok.test', 'telepon' => $lain->telepon])
            ->assertSessionHasErrors('pengguna');

        $this->post(route('pengguna.password', $danish), ['password' => 'barubaru1', 'password_confirmation' => 'barubaru1', 'wajib_ganti' => '1'])
            ->assertRedirect(route('pengguna.index'));
        $this->assertTrue(password_verify('barubaru1', $danish->fresh()->password));
        $this->assertTrue($danish->fresh()->wajib_ganti_password);
        $this->post(route('pengguna.password', $admin), ['password' => 'barubaru1', 'password_confirmation' => 'barubaru1'])->assertSessionHasErrors('password');

        // WhatsApp belum aktif: Reset password tidak mengubah password (supaya akun tidak terkunci).
        $sebelum = $danish->fresh()->password;
        $this->post(route('pengguna.reset', $danish))->assertSessionHasErrors('pengguna');
        $this->assertSame($sebelum, $danish->fresh()->password);

        // Hanya pemegang pengguna.kelola.
        $this->actingAs($lain)->get(route('pengguna.edit', $danish))->assertForbidden();
    }

    public function test_akun_wali_tidak_bisa_lewat_form_staf(): void
    {
        $admin = $this->staf('admin', 'eris');
        $wali = $this->wali($this->santri())->assignRole('wali_santri');
        $this->actingAs($admin)->get(route('pengguna.edit', $wali))->assertNotFound();
        $this->post(route('pengguna.password', $wali), ['password' => 'barubaru1', 'password_confirmation' => 'barubaru1'])->assertNotFound();
    }
}
