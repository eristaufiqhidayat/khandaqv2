<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Menu staf dari config/khandaq-menu.php, disaring menurut IZIN pengguna (bukan nama peran).
 * Dipakai sidebar dan untuk menentukan halaman pertama setelah login.
 */
class MenuStaf
{
    /** @return list<array{label:string, route:string, izin:\App\Enums\Izin}> */
    public static function untuk(User $user): array
    {
        return array_values(array_filter(
            config('khandaq-menu'),
            fn (array $m) => Route::has($m['route']) && $user->hasPermissionTo($m['izin']->value),
        ));
    }

    /** Halaman awal: Ringkasan keuangan bila boleh, selain itu menu pertama yang dimiliki. */
    public static function halamanAwal(User $user): ?string
    {
        $menu = self::untuk($user);

        return $menu[0]['route'] ?? null;
    }
}
