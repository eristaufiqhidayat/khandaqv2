<?php

namespace App\Exceptions;

/** Pelanggaran aturan bisnis (saldo kurang, tanpa izin, pengaju = penyetuju, dll.). */
class AturanDilanggar extends \RuntimeException {}
