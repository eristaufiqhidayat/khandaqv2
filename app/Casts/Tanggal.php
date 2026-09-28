<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Kolom DATE: disimpan persis "Y-m-d" (tanpa jam) di semua database, dibaca sebagai CarbonImmutable.
 * Mencegah perbedaan perilaku MySQL vs SQLite saat membandingkan tanggal ('2026-02-01' vs '2026-02-01 00:00:00').
 */
class Tanggal implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toDateString();
    }
}
