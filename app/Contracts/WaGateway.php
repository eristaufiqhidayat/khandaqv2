<?php

namespace App\Contracts;

use App\Whatsapp\HasilKirim;

/**
 * Satu pintu ke penyedia WhatsApp. Aplikasi lama memakai dua: ngirimwa (teks bebas) dan
 * Meta Cloud API (WhatsApp Business resmi; di luar jendela 24 jam WAJIB memakai template yang disetujui Meta).
 */
interface WaGateway
{
    /** @param string $telepon nomor lokal (08xx) atau internasional; gateway yang mengubah formatnya. */
    public function kirim(string $telepon, string $isi, ?string $template = null): HasilKirim;

    public function nama(): string;
}
