<?php

namespace App\Notifiers;

use App\Contracts\Notifier;
use App\Models\Peringatan;

/** Notifier untuk pengujian/pengembangan: hanya menyimpan pesan di memori. */
class LogNotifier implements Notifier
{
    /** @var list<array{santri_id:int, tahap:string, pesan:string}> */
    public array $terkirim = [];

    public function kirimPeringatan(Peringatan $peringatan, string $pesan): bool
    {
        $this->terkirim[] = ['santri_id' => $peringatan->santri_id, 'tahap' => $peringatan->tahap->value, 'pesan' => $pesan];

        return true;
    }
}
