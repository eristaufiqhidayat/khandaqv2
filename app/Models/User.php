<?php

namespace App\Models;

use App\Models\Concerns\PenggunaKhandaq;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\CausesActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use CausesActivity, HasFactory, HasRoles, Notifiable, PenggunaKhandaq;

    protected $fillable = ['name', 'username', 'email', 'telepon', 'telepon_menunggu', 'aktif', 'password',
        'nik', 'alamat', 'pekerjaan', 'wajib_ganti_password', 'password_diubah_pada', 'password_lama', 'legacy_id_orangtua'];

    protected $hidden = ['password', 'password_lama', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'aktif' => 'boolean',
            'wajib_ganti_password' => 'boolean',
            'password_diubah_pada' => 'datetime',
        ];
    }
}
