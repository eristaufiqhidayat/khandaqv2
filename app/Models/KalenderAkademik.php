<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class KalenderAkademik extends Model
{
    protected $table = 'kalender_akademik';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tanggal_mulai' => 'date', 'tanggal_selesai' => 'date'];
    }

    /** Kegiatan yang (sebagian) jatuh di antara dua tanggal. */
    public function scopeAntara(Builder $q, CarbonInterface $awal, CarbonInterface $akhir): Builder
    {
        return $q->where('tanggal_mulai', '<=', $akhir->toDateString())
            ->where(fn ($x) => $x->where(fn ($y) => $y->whereNull('tanggal_selesai')->where('tanggal_mulai', '>=', $awal->toDateString()))
                ->orWhere('tanggal_selesai', '>=', $awal->toDateString()))
            ->orderBy('tanggal_mulai');
    }

    public function rentang(): string
    {
        $m = $this->tanggal_mulai;
        $s = $this->tanggal_selesai;
        if (! $s || $s->isSameDay($m)) {
            return $m->translatedFormat('l, d F Y');
        }

        return $m->isSameMonth($s)
            ? $m->translatedFormat('d').'–'.$s->translatedFormat('d F Y')
            : $m->translatedFormat('d M').' – '.$s->translatedFormat('d M Y');
    }
}
