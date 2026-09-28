<?php

namespace App\Enums;

enum StatusKeringanan: string
{
    case Diajukan = 'diajukan';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
    case Dibatalkan = 'dibatalkan';
}
