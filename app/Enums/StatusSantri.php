<?php

namespace App\Enums;

enum StatusSantri: string
{
    case Calon = 'calon';     // sudah daftar, belum masuk (tagihan DSB sudah bisa dibuat)
    case Aktif = 'aktif';
    case Alumni = 'alumni';
    case Keluar = 'keluar';   // termasuk dipulangkan
}
