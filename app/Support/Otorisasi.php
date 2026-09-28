<?php

namespace App\Support;

use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Models\User;

final class Otorisasi
{
    public static function pastikan(?User $user, Izin $izin): void
    {
        if (! $user || ! $user->hasPermissionTo($izin->value)) {
            throw new AturanDilanggar("Tidak punya izin {$izin->value}.");
        }
    }
}
