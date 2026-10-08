<?php

namespace App\Models;

use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nilai satu santri untuk satu mapel di satu semester.
 * Rata-rata harian = rata-rata kolom harian & tugas yang terisi.
 * Nilai akhir = bobot harian/UTS/UAS milik mapel (Mapel::bobot) atas komponen yang terisi.
 */
class Nilai extends Model
{
    use DicatatAudit;

    /** Kolom nilai harian => judul kolom (urutan tampil di Input & Rekap nilai). */
    public const LABEL_HARIAN = ['harian_1' => 'H1', 'harian_2' => 'H2', 'harian_3' => 'H3', 'harian_4' => 'H4', 'harian_5' => 'H5', 'tugas' => 'Tugas'];

    public const HARIAN = ['harian_1', 'harian_2', 'harian_3', 'harian_4', 'harian_5', 'tugas'];

    protected $table = 'nilai';

    protected $guarded = ['id'];

    public function getActivitylogOptions(): \Spatie\Activitylog\Support\LogOptions
    {
        return \Spatie\Activitylog\Support\LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('nilai');
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class);
    }

    public function mapel(): BelongsTo
    {
        return $this->belongsTo(Mapel::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function rataHarian(): ?float
    {
        $isi = array_values(array_filter(array_map(fn ($k) => $this->{$k}, self::HARIAN), fn ($v) => $v !== null));

        return $isi === [] ? null : round(array_sum($isi) / count($isi), 1);
    }

    /** Null bila belum ada komponen yang terisi. Bobot dibagi ulang di antara komponen yang terisi. */
    public function nilaiAkhir(?array $bobot = null): ?float
    {
        $bobot ??= $this->mapel?->bobot() ?? config('khandaq.nilai.bobot');
        $komponen = array_filter([
            'harian' => $this->rataHarian(), 'uts' => $this->uts, 'uas' => $this->uas,
        ], fn ($v) => $v !== null);
        $total = array_sum(array_intersect_key($bobot, $komponen));
        if ($komponen === [] || $total <= 0) {
            return null;
        }

        return round(array_sum(array_map(fn ($k, $v) => $v * $bobot[$k], array_keys($komponen), $komponen)) / $total, 1);
    }

    /** Predikat dari nilai akhir: A >= 90, B >= 80, C >= KKM, D di bawah KKM. */
    public static function predikat(?float $nilai, int $kkm): ?string
    {
        return match (true) {
            $nilai === null => null,
            $nilai < $kkm => 'D',
            $nilai >= 90 => 'A',
            $nilai >= 80 => 'B',
            default => 'C',
        };
    }
}
