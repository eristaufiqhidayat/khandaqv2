<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Enums\ArahMutasi;
use App\Enums\StatusTagihan;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tagihan extends Model
{
    use DicatatAudit;

    protected $table = 'tagihan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => StatusTagihan::class,
            'periode' => Tanggal::class,
            'jatuh_tempo' => Tanggal::class,
            'nominal' => 'integer',
            'potongan' => 'integer',
            'terbayar' => 'integer',
        ];
    }

    public function scopeTerbuka(Builder $q): Builder
    {
        return $q->whereIn('status', StatusTagihan::terbuka());
    }

    public function netto(): int
    {
        return max(0, $this->nominal - $this->potongan);
    }

    public function sisa(): int
    {
        return max(0, $this->netto() - $this->terbayar);
    }

    /** Hitung ulang `terbayar` dari ledger dan tentukan status. Dipanggil setelah setiap pembayaran. */
    public function segarkanStatus(): void
    {
        if ($this->status === StatusTagihan::Dibatalkan) {
            return;
        }
        $this->terbayar = (int) $this->pembayaran()->where('arah', ArahMutasi::Debit->value)->sum('nominal');
        $this->status = match (true) {
            $this->terbayar >= $this->netto() => StatusTagihan::Lunas,
            $this->terbayar > 0 => StatusTagihan::Sebagian,
            default => StatusTagihan::Belum,
        };
        $this->save();
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(TabunganMutasi::class);
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class);
    }

    public function jenisTagihan(): BelongsTo
    {
        return $this->belongsTo(JenisTagihan::class);
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function keringanan(): BelongsTo
    {
        return $this->belongsTo(Keringanan::class);
    }
}
