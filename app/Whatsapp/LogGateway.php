<?php

namespace App\Whatsapp;

use App\Contracts\WaGateway;

/** Tanpa kredensial (lokal/pengujian): tidak mengirim apa pun, hanya mencatat. Nomor berakhiran 000 dianggap gagal. */
class LogGateway implements WaGateway
{
    /** @var list<array{telepon:string, isi:string, template:?string}> */
    public array $terkirim = [];

    public function nama(): string
    {
        return 'log';
    }

    public function kirim(string $telepon, string $isi, ?string $template = null): HasilKirim
    {
        if (str_ends_with($telepon, '000')) {
            return new HasilKirim(false, null, 'Nomor tidak terdaftar di WhatsApp');
        }
        $this->terkirim[] = compact('telepon', 'isi', 'template');

        return new HasilKirim(true, 'log.'.count($this->terkirim).'.'.bin2hex(random_bytes(4)));
    }
}
