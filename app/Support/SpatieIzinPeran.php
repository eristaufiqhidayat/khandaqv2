<?php

namespace App\Support;

use App\Contracts\PenyimpanIzinPeran;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SpatieIzinPeran implements PenyimpanIzinPeran
{
    public function izinPeran(string $peran): array
    {
        return Role::findByName($peran, 'web')->permissions->pluck('name')->all();
    }

    public function beri(string $peran, string $izin): void
    {
        Role::findByName($peran, 'web')->givePermissionTo($izin);
        app(PermissionRegistrar::class)->forgetCachedPermissions(); // berlaku seketika
    }

    public function cabut(string $peran, string $izin): void
    {
        Role::findByName($peran, 'web')->revokePermissionTo($izin);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function daftarPeran(): array
    {
        return Role::orderBy('id')->pluck('name')->all();
    }
}
