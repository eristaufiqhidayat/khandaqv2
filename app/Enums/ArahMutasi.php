<?php

namespace App\Enums;

enum ArahMutasi: string
{
    case Kredit = 'kredit'; // menambah saldo
    case Debit = 'debit';   // mengurangi saldo
}
