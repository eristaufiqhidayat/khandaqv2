<?php

namespace App\Enums;

enum StatusBankMutasi: string
{
    case Baru = 'baru';
    case Cocok = 'cocok';         // tertaut ke setoran
    case Ditinjau = 'ditinjau';   // perlu keputusan Admin Office
    case Diabaikan = 'diabaikan'; // bukan setoran santri (payroll, biaya bank, dll.)
}
