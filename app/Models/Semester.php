<?php

namespace App\Models;

use App\Casts\Tanggal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Semester extends Model
{
    protected $table = 'semester';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mulai' => Tanggal::class, 'selesai' => Tanggal::class, 'aktif' => 'boolean'];
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }
}
