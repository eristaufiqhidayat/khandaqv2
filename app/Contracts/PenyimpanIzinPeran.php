<?php

namespace App\Contracts;

/** Tempat penyimpanan izin per peran. Produksi: spatie/laravel-permission (SpatieIzinPeran). */
interface PenyimpanIzinPeran
{
    /** @return list<string> nama izin milik peran */
    public function izinPeran(string $peran): array;

    public function beri(string $peran, string $izin): void;

    public function cabut(string $peran, string $izin): void;

    /** @return list<string> */
    public function daftarPeran(): array;
}
