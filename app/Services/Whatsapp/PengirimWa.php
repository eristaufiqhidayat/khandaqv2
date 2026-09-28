<?php

namespace App\Services\Whatsapp;

use App\Contracts\WaGateway;
use App\Models\WaPesan;
use Carbon\CarbonImmutable;

/** Mengirim satu baris wa_pesan lewat gateway aktif dan mencatat hasilnya. */
class PengirimWa
{
    public function __construct(
        private WaGateway $gateway,
        private ?string $templateBawaan = null, // template Meta untuk pesan sistem (peringatan), mis. "pemberitahuan_wali"
    ) {}

    public function gateway(): WaGateway
    {
        return $this->gateway;
    }

    public function butuhTemplate(): bool
    {
        return $this->gateway->nama() === 'meta';
    }

    public function kirim(WaPesan $pesan, ?string $template = null): WaPesan
    {
        if ($pesan->status !== 'antri') {
            return $pesan; // job diulang setelah sukses: jangan kirim dua kali
        }
        $template ??= $pesan->siaran?->template ?? $this->templateBawaan;
        $hasil = $this->gateway->kirim($pesan->telepon, $pesan->isi, $this->butuhTemplate() ? $template : null);
        $pesan->update([
            'status' => $hasil->ok ? 'terkirim' : 'gagal',
            'vendor' => $this->gateway->nama(),
            'id_vendor' => $hasil->idVendor,
            'galat' => $hasil->galat,
            'dikirim_pada' => CarbonImmutable::now(),
            'status_pada' => CarbonImmutable::now(),
        ]);
        $pesan->siaran?->segarkanJumlah();

        return $pesan;
    }
}
