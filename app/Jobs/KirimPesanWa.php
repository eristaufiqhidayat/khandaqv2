<?php

namespace App\Jobs;

use App\Models\WaPesan;
use App\Services\Whatsapp\PengirimWa;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Satu pesan = satu job, diberi jeda beberapa detik antar-nomor agar nomor pengirim tidak diblokir. */
class KirimPesanWa implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $pesanId) {}

    public function handle(PengirimWa $pengirim): void
    {
        if ($pesan = WaPesan::find($this->pesanId)) {
            $pengirim->kirim($pesan);
        }
    }
}
