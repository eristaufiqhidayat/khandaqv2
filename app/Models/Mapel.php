<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Mata pelajaran. KKM dan bobot nilai akhir per mapel menentukan nilai akhir & tuntas/belum di Rekap nilai. */
class Mapel extends Model
{
    protected $table = 'mapel';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean', 'kkm' => 'integer', 'bobot_harian' => 'integer', 'bobot_uts' => 'integer', 'bobot_uas' => 'integer'];
    }

    /** Bobot nilai akhir (persen, jumlah 100): ['harian' => 50, 'uts' => 25, 'uas' => 25]. */
    public function bobot(): array
    {
        return ['harian' => $this->bobot_harian ?? 50, 'uts' => $this->bobot_uts ?? 25, 'uas' => $this->bobot_uas ?? 25];
    }

    /** Teks rumus untuk layar, mis. "50% harian + 25% UTS + 25% UAS". */
    public function rumus(): string
    {
        $b = $this->bobot();

        return "{$b['harian']}% harian + {$b['uts']}% UTS + {$b['uas']}% UAS";
    }

    public function scopeUrut(Builder $q): Builder
    {
        return $q->orderBy('urutan')->orderBy('nama');
    }
}
