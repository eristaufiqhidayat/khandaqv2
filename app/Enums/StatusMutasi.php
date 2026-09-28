<?php

namespace App\Enums;

enum StatusMutasi: string
{
    case Pending = 'pending';             // transfer dilaporkan, belum cocok dengan mutasi bank
    case Terverifikasi = 'terverifikasi'; // dihitung ke saldo
    case Ditolak = 'ditolak';
}
