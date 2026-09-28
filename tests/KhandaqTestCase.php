<?php

namespace Tests;

use App\Enums\Izin;
use App\Models\User;
use Database\Seeders\MasterKeuanganSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TarifContohSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Basis tes di proyek Laravel (database uji: SQLite in-memory atau MySQL terpisah). */
abstract class KhandaqTestCase extends TestCase
{
    use BantuanKhandaq, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, MasterKeuanganSeeder::class, TarifContohSeeder::class]);
    }

    protected function beriIzin(User $user, Izin ...$izin): User
    {
        $user->givePermissionTo(array_map(fn (Izin $i) => $i->value, $izin));

        return $user;
    }

    protected function buatUser(string $nama): User
    {
        return User::create(['name' => $nama, 'email' => uniqid().'@test.local', 'password' => 'rahasia123']);
    }
}
