<?php

namespace App\Whatsapp;

use App\Contracts\WaGateway;
use Illuminate\Support\Facades\Http;
use Throwable;

/** app.ngirimwa.com — dipakai aplikasi lama. Kredensial dari .env (WA_NGIRIMWA_APPKEY/AUTHKEY), bukan dari tabel. */
class NgirimwaGateway implements WaGateway
{
    public function __construct(
        private string $appKey,
        private string $authKey,
        private string $endpoint = 'https://app.ngirimwa.com/api/create-message',
    ) {}

    public function nama(): string
    {
        return 'ngirimwa';
    }

    public function kirim(string $telepon, string $isi, ?string $template = null): HasilKirim
    {
        try {
            $res = Http::asForm()->timeout(20)->post($this->endpoint, [
                'appkey' => $this->appKey, 'authkey' => $this->authKey,
                'to' => HasilKirim::nomorInternasional($telepon), 'message' => $isi, 'sandbox' => 'false',
            ]);
            // Aplikasi lama salah membaca respons (isset(...) == "Success" selalu benar). Di sini dicek sungguhan.
            $ok = $res->successful() && ($res->json('message_status') === 'Success');

            return new HasilKirim($ok, $res->json('data.id') ?? $res->json('data.message_id'), $ok ? null : mb_substr($res->body(), 0, 500));
        } catch (Throwable $e) {
            return new HasilKirim(false, null, mb_substr($e->getMessage(), 0, 500));
        }
    }
}
