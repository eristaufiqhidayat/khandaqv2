<?php

namespace App\Enums;

enum Frekuensi: string
{
    case Bulanan = 'bulanan';          // SPP, laundry, kesehatan
    case PerSemester = 'per_semester'; // PTS, PAS
    case Tahunan = 'tahunan';          // Daftar Ulang (santri lama)
    case Sekali = 'sekali';            // DSB (santri baru)
    case PerSetoran = 'per_setoran';   // infak: dipotong setiap ada setoran
    case Insidental = 'insidental';    // kegiatan, buku, rihlah
}
