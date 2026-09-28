<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SantriKelas extends Model
{
    protected $table = 'santri_kelas';

    protected $guarded = ['id'];

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class);
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }
}
