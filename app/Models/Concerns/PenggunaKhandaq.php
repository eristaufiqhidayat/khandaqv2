<?php

namespace App\Models\Concerns;

use App\Models\Santri;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Relasi & helper Khandaq untuk model User (dipisah agar mudah dipakai di User bawaan Laravel). */
trait PenggunaKhandaq
{
    /** Anak-anak dari akun wali (semua anak, bukan hanya anak pertama seperti portal lama). */
    public function anak(): BelongsToMany
    {
        return $this->belongsToMany(Santri::class, 'wali_santri')->withPivot('hubungan')->withTimestamps();
    }

    public function adalahWaliDari(Santri $santri): bool
    {
        return $this->anak()->whereKey($santri->getKey())->exists();
    }
}
