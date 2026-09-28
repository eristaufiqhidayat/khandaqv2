<?php

namespace App\Policies;

use App\Enums\Izin;
use App\Models\Raport;
use App\Models\User;
use App\Services\AksesRaport;

class RaportPolicy
{
    public function __construct(private AksesRaport $akses) {}

    public function view(User $user, Raport $raport): bool
    {
        return $this->akses->bolehLihat($user, $raport);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Izin::RaportUnggah->value);
    }
}
