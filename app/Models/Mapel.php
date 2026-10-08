<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Mata pelajaran. KKM per mapel menentukan tuntas/belum di Input & Rekap nilai. */
class Mapel extends Model
{
    protected $table = 'mapel';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean', 'kkm' => 'integer'];
    }

    public function scopeUrut(Builder $q): Builder
    {
        return $q->orderBy('urutan')->orderBy('nama');
    }
}
