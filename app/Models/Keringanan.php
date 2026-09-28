<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Enums\JenisKeringanan;
use App\Enums\StatusKeringanan;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Beasiswa, diskon DSB/DU, dan dispensasi raport. Berlaku hanya setelah disetujui Keuangan. */
class Keringanan extends Model
{
    use DicatatAudit;

    protected $table = 'keringanan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'jenis' => JenisKeringanan::class,
            'status' => StatusKeringanan::class,
            'persen' => 'float',
            'nominal' => 'integer',
            'berlaku_sampai' => Tanggal::class,
            'diputuskan_pada' => 'immutable_datetime',
        ];
    }

    public function scopeDisetujui(Builder $q): Builder
    {
        return $q->where('status', StatusKeringanan::Disetujui->value);
    }

    /** Potongan rupiah untuk satu tagihan bernominal $nominal. */
    public function potonganUntuk(int $nominal): int
    {
        $potongan = $this->nominal !== null
            ? $this->nominal
            : (int) round($nominal * ($this->persen ?? 0) / 100);

        return min($nominal, max(0, $potongan));
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class);
    }

    public function jenisTagihan(): BelongsTo
    {
        return $this->belongsTo(JenisTagihan::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }
}
