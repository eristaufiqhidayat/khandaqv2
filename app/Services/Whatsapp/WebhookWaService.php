<?php

namespace App\Services\Whatsapp;

use App\Models\Peringatan;
use App\Models\User;
use App\Models\WaPesan;
use App\Models\WaWebhook;
use App\Services\PendaftaranService;
use Carbon\CarbonImmutable;

/**
 * Menerima callback gateway (pengganti tbl_webhook yang hanya menyimpan JSON mentah tanpa diolah).
 * Meta: status pesan (sent/delivered/read/failed) dan pesan masuk dari wali.
 * Setiap payload tetap disimpan mentah di wa_webhook untuk penelusuran.
 */
class WebhookWaService
{
    private const STATUS_META = ['sent' => 'terkirim', 'delivered' => 'diterima', 'read' => 'dibaca', 'failed' => 'gagal'];

    /** GET verifikasi saat mendaftarkan URL webhook di Meta. Mengembalikan challenge bila token cocok. */
    public static function verifikasiMeta(?string $mode, ?string $token, ?string $challenge, string $tokenBenar): ?string
    {
        return $mode === 'subscribe' && $tokenBenar !== '' && hash_equals($tokenBenar, (string) $token) ? $challenge : null;
    }

    /** Header X-Hub-Signature-256 = "sha256=" + HMAC body mentah dengan App Secret. Tanpa ini siapa pun bisa memalsukan status. */
    public static function tandaSah(string $bodyMentah, ?string $header, string $appSecret): bool
    {
        return $appSecret !== '' && $header !== null
            && hash_equals('sha256='.hash_hmac('sha256', $bodyMentah, $appSecret), $header);
    }

    public function terima(string $vendor, array $payload): WaWebhook
    {
        $log = WaWebhook::create(['vendor' => $vendor, 'payload' => $payload]);
        $n = match ($vendor) {
            'meta' => $this->prosesMeta($payload),
            'ngirimwa' => $this->prosesNgirimwa($payload),
            default => 0,
        };
        $log->update(['diproses' => true, 'catatan' => "{$n} kejadian diolah"]);

        return $log;
    }

    private function prosesMeta(array $p): int
    {
        $n = 0;
        foreach ($p['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $v = $change['value'] ?? [];
                foreach ($v['statuses'] ?? [] as $s) {
                    $n += (int) $this->perbaruiStatus(
                        (string) ($s['id'] ?? ''), self::STATUS_META[$s['status'] ?? ''] ?? null,
                        isset($s['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $s['timestamp']) : CarbonImmutable::now(),
                        $s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? null,
                    );
                }
                foreach ($v['messages'] ?? [] as $m) {
                    $isi = $m['text']['body'] ?? $m['button']['text'] ?? $m['interactive']['button_reply']['title'] ?? '['.($m['type'] ?? 'pesan').']';
                    $n += (int) $this->pesanMasuk((string) ($m['from'] ?? ''), $isi, $m['id'] ?? null);
                }
            }
        }

        return $n;
    }

    /** ngirimwa mengirim form: nohp, message (lihat contoh di tbl_webhook lama). */
    private function prosesNgirimwa(array $p): int
    {
        $no = $p['nohp'] ?? $p['from'] ?? null;
        $isi = $p['message'] ?? $p['pesan'] ?? null;

        return $no && $isi ? (int) $this->pesanMasuk((string) $no, (string) $isi, $p['id'] ?? null) : 0;
    }

    private function perbaruiStatus(string $idVendor, ?string $status, CarbonImmutable $waktu, ?string $galat): bool
    {
        $pesan = $idVendor !== '' ? WaPesan::where('id_vendor', $idVendor)->first() : null;
        if (! $pesan || ! $status) {
            return false;
        }
        // Status tidak boleh mundur (callback "delivered" bisa tiba setelah "read"); "gagal" menimpa hanya bila belum diterima.
        $lama = WaPesan::URUTAN[$pesan->status] ?? 0;
        $baru = WaPesan::URUTAN[$status];
        $boleh = $status === 'gagal' ? $lama <= WaPesan::URUTAN['terkirim'] : $baru > $lama;
        if (! $boleh) {
            return false;
        }
        $pesan->update(['status' => $status, 'status_pada' => $waktu, 'galat' => $status === 'gagal' ? $galat : $pesan->galat]);
        $pesan->siaran?->segarkanJumlah();

        // Peringatan tunggakan yang sudah dibaca di WhatsApp = bukti wali sudah membaca (syarat keputusan pemulangan).
        if ($status === 'dibaca' && $pesan->peringatan_id) {
            Peringatan::whereKey($pesan->peringatan_id)->whereNull('dibaca_pada')->update(['dibaca_pada' => $waktu]);
        }

        return true;
    }

    private function pesanMasuk(string $dari, string $isi, ?string $idVendor): bool
    {
        if ($idVendor && WaPesan::where('id_vendor', $idVendor)->exists()) {
            return false; // Meta bisa mengirim callback yang sama lebih dari sekali
        }
        $telepon = PendaftaranService::normalTelepon($dari);
        WaPesan::create([
            'arah' => 'masuk', 'telepon' => $telepon, 'isi' => mb_substr($isi, 0, 4000), 'status' => 'masuk',
            'user_id' => User::where('telepon', $telepon)->value('id'),
            'id_vendor' => $idVendor, 'status_pada' => CarbonImmutable::now(),
        ]);

        return true;
    }
}
