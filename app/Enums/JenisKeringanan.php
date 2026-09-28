<?php

namespace App\Enums;

enum JenisKeringanan: string
{
    case Beasiswa = 'beasiswa';                  // SPP; diinput Admin, disetujui Keuangan
    case Diskon = 'diskon';                      // DSB / Daftar Ulang; disetujui Keuangan
    case DispensasiRaport = 'dispensasi_raport'; // raport boleh dibuka walau menunggak; disetujui Keuangan
}
