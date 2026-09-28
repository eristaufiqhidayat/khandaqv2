<?php

namespace App\Notifiers;

use App\Contracts\Notifier;
use App\Models\Peringatan;
use App\Models\WaPesan;
use App\Services\Whatsapp\PengirimWa;

/**
 * Peringatan tunggakan ke setiap wali santri lewat gateway WhatsApp aktif (ngirimwa atau Meta).
 * Setiap kiriman tercatat di wa_pesan (peringatan_id), sehingga status "dibaca" dari Meta
 * otomatis menjadi bukti baca pada peringatan.
 */
class WhatsappNotifier implements Notifier
{
    public function __construct(private PengirimWa $pengirim) {}

    public function kirimPeringatan(Peringatan $peringatan, string $pesan): bool
    {
        $ok = false;
        foreach ($peringatan->santri->wali()->where('aktif', true)->whereNotNull('telepon')->get() as $wali) {
            $p = WaPesan::create([
                'peringatan_id' => $peringatan->id, 'user_id' => $wali->id,
                'telepon' => $wali->telepon, 'isi' => $pesan, 'status' => 'antri',
            ]);
            $ok = $this->pengirim->kirim($p)->status === 'terkirim' || $ok;
        }

        return $ok;
    }
}
