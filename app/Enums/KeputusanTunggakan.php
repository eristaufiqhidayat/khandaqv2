<?php

namespace App\Enums;

/** Keputusan Keuangan pada tahap tunggakan 3 bulan. Sistem tidak memulangkan santri otomatis. */
enum KeputusanTunggakan: string
{
    case Pemulangan = 'pemulangan';
    case Dispensasi = 'dispensasi';
    case Cicilan = 'cicilan';
    case Lunas = 'lunas';
}
