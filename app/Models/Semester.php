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

    public function raport(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Raport::class);
    }

    /** Nama baku, mis. "Semester 1 (Ganjil) 2025/2026". Dipakai juga oleh sinkronisasi agar nama dari data lama seragam. */
    public static function namaBaku(int $nomor, string $namaTa): string
    {
        return 'Semester '.$nomor.' ('.($nomor === 1 ? 'Ganjil' : 'Genap').') '.$namaTa;
    }

    /** Label untuk layar: selalu menyebut tahun ajaran, walau nama tersimpan tidak. */
    public function getLabelAttribute(): string
    {
        $ta = $this->tahunAjaran?->nama;

        return $ta && ! str_contains((string) $this->nama, $ta) ? trim($this->nama.' '.$ta) : (string) $this->nama;
    }
}
