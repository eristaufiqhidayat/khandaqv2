<?php

namespace App\Whatsapp;

final class HasilKirim
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $idVendor = null,
        public readonly ?string $galat = null,
    ) {}

    /** 08123 / +62 812-3 / 628123 -> 628123 (format yang diminta kedua gateway). */
    public static function nomorInternasional(string $telepon): string
    {
        $d = preg_replace('/\D/', '', $telepon);

        return str_starts_with($d, '0') ? '62'.substr($d, 1) : $d;
    }
}
