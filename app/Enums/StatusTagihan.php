<?php

namespace App\Enums;

enum StatusTagihan: string
{
    case Belum = 'belum';
    case Sebagian = 'sebagian';
    case Lunas = 'lunas';
    case Dibatalkan = 'dibatalkan';

    /** @return list<string> */
    public static function terbuka(): array
    {
        return [self::Belum->value, self::Sebagian->value];
    }
}
