<?php

namespace App\Models;

use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Penerima gaji (guru & karyawan) lewat payroll BSI. */
class Pegawai extends Model
{
    use DicatatAudit;

    protected $table = 'pegawai';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean', 'nominal_tetap' => 'integer', 'urutan' => 'integer'];
    }

    public function scopeAktif(Builder $q): Builder
    {
        return $q->where('aktif', true);
    }

    public function scopeUrut(Builder $q): Builder
    {
        return $q->orderBy('urutan')->orderBy('nama');
    }
}
