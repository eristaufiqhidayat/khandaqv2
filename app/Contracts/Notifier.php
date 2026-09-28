<?php

namespace App\Contracts;

use App\Models\Peringatan;

/** Pengirim notifikasi ke wali (WhatsApp, portal). */
interface Notifier
{
    /** @return bool true bila terkirim */
    public function kirimPeringatan(Peringatan $peringatan, string $pesan): bool;
}
