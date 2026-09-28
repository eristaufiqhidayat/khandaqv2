<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Token aplikasi Android wali. Token asli: "<id>|<acak 48>"; yang disimpan hanya hash-nya. */
class ApiToken extends Model
{
    protected $table = 'api_token';

    protected $guarded = ['id'];

    /** Token berlaku 180 hari sejak terakhir dipakai (diperpanjang otomatis saat aplikasi dibuka). */
    public const HARI_BERLAKU = 180;

    protected function casts(): array
    {
        return ['terakhir_dipakai_pada' => 'immutable_datetime', 'kedaluwarsa_pada' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{0: ApiToken, 1: string} [model, token asli] */
    public static function terbitkan(User $user, ?string $perangkat): array
    {
        $acak = Str::random(48);
        $t = static::create([
            'user_id' => $user->id, 'perangkat' => $perangkat ? mb_substr($perangkat, 0, 100) : null,
            'token_hash' => hash('sha256', $acak), 'terakhir_dipakai_pada' => CarbonImmutable::now(),
            'kedaluwarsa_pada' => CarbonImmutable::now()->addDays(self::HARI_BERLAKU),
        ]);

        return [$t, $t->id.'|'.$acak];
    }

    public static function cari(?string $token): ?self
    {
        if (! $token || ! str_contains($token, '|')) {
            return null;
        }
        [$id, $acak] = explode('|', $token, 2);
        $t = ctype_digit($id) ? static::find((int) $id) : null;

        return $t && hash_equals($t->token_hash, hash('sha256', $acak)) && $t->kedaluwarsa_pada->isFuture() ? $t : null;
    }

    public function catatPemakaian(): void
    {
        $now = CarbonImmutable::now();
        if (! $this->terakhir_dipakai_pada || $this->terakhir_dipakai_pada->lt($now->subMinutes(10))) {
            $this->forceFill(['terakhir_dipakai_pada' => $now, 'kedaluwarsa_pada' => $now->addDays(self::HARI_BERLAKU)])->saveQuietly();
        }
    }
}
