<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankImpor extends Model
{
    protected $table = 'bank_impor';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['periode_awal' => \App\Casts\Tanggal::class, 'periode_akhir' => \App\Casts\Tanggal::class];
    }

    public function pengimpor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'diimpor_oleh');
    }

    public function baris(): HasMany
    {
        return $this->hasMany(BankMutasi::class);
    }
}
