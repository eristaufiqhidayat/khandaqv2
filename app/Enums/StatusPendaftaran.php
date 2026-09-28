<?php

namespace App\Enums;

enum StatusPendaftaran: string
{
    case Baru = 'baru';                   // formulir masuk
    case FormulirLunas = 'formulir_lunas';
    case Diterima = 'diterima';           // santri (calon) + akun wali + tagihan DSB dibuat
    case Ditolak = 'ditolak';
    case Batal = 'batal';
}
