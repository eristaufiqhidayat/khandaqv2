<?php

namespace App\Enums;

enum TahapPeringatan: string
{
    case Pengingat = 'pengingat';     // 3 hari sebelum akhir bulan
    case Terlambat1 = 'terlambat_1';  // tgl 1 bulan berikutnya
    case Terlambat2 = 'terlambat_2';  // dua bulan tertunggak
    case Terlambat3 = 'terlambat_3';  // tiga bulan: kandidat pemulangan, surat resmi ke wali

    public static function dariBulanTunggakan(int $bulan): ?self
    {
        return match (true) {
            $bulan >= 3 => self::Terlambat3,
            $bulan === 2 => self::Terlambat2,
            $bulan === 1 => self::Terlambat1,
            default => null,
        };
    }
}
