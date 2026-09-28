<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Aturan validasi "Lapor transfer" wali (portal web dan aplikasi Android). */
final class LaporTransfer
{
    public const MAKS_HARI_LALU = 60;

    public static function aturan(): array
    {
        return [
            'nominal' => 'required|integer|min:1000|max:100000000',
            'tanggal' => 'required|date|before_or_equal:today|after_or_equal:'.CarbonImmutable::today()->subDays(self::MAKS_HARI_LALU)->toDateString(),
            'bukti' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:8192',
            'catatan' => 'nullable|string|max:200',
        ];
    }

    public static function pesan(): array
    {
        return [
            'nominal.required' => 'Isi nominal transfer.', 'nominal.min' => 'Nominal minimal Rp1.000.',
            'tanggal.required' => 'Isi tanggal transfer.', 'tanggal.before_or_equal' => 'Tanggal transfer tidak boleh di masa depan.',
            'tanggal.after_or_equal' => 'Transfer lebih dari '.self::MAKS_HARI_LALU.' hari lalu: hubungi Admin Office.',
            'bukti.required' => 'Lampirkan foto bukti transfer.', 'bukti.mimes' => 'Bukti harus foto (JPG/PNG) atau PDF.',
            'bukti.max' => 'Ukuran bukti maksimal 8 MB.',
        ];
    }

    public static function keterangan(?string $catatan): string
    {
        return 'Lapor transfer wali'.(trim((string) $catatan) !== '' ? ': '.trim($catatan) : '');
    }
}
