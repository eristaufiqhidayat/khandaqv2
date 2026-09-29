<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu kali pengiriman payroll BSI (biasanya satu per bulan). */
class Penggajian extends Model
{
    use DicatatAudit;

    public const DRAF = 'draf';
    public const FINAL = 'final';

    protected $table = 'penggajian';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['periode' => Tanggal::class, 'tanggal_transfer' => Tanggal::class, 'difinalkan_pada' => 'datetime'];
    }

    public function rincian(): HasMany
    {
        return $this->hasMany(PenggajianRincian::class)->orderBy('urutan')->orderBy('id');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function final(): bool
    {
        return $this->status === self::FINAL;
    }

    public function namaFile(): string
    {
        return 'gaji_'.$this->tanggal_transfer->format('d-m-Y').'.txt';
    }
}
