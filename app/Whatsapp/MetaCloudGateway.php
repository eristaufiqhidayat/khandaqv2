<?php

namespace App\Whatsapp;

use App\Contracts\WaGateway;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp Business Cloud API (Meta). Log aplikasi lama: 73 dari 340 kiriman Meta gagal, hampir semuanya karena
 * body dikirim sebagai form, bukan JSON ("Param text must be a JSON object") dan access token kedaluwarsa.
 * Di sini body JSON, token permanen (System User) dari .env, dan isi siaran masuk sebagai parameter {{1}} template.
 */
class MetaCloudGateway implements WaGateway
{
    public function __construct(
        private string $phoneNumberId,
        private string $token,
        private string $versi = 'v21.0',
        private string $bahasa = 'id',
    ) {}

    public function nama(): string
    {
        return 'meta';
    }

    public function kirim(string $telepon, string $isi, ?string $template = null): HasilKirim
    {
        $body = ['messaging_product' => 'whatsapp', 'to' => HasilKirim::nomorInternasional($telepon)];
        if ($template) {
            $body += ['type' => 'template', 'template' => [
                'name' => $template, 'language' => ['code' => $this->bahasa],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $isi]]]],
            ]];
        } else {
            // Teks bebas hanya diterima Meta bila wali membalas dalam 24 jam terakhir.
            $body += ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $isi]];
        }
        try {
            $res = Http::withToken($this->token)->acceptJson()->timeout(20)
                ->post("https://graph.facebook.com/{$this->versi}/{$this->phoneNumberId}/messages", $body);
            if ($res->successful() && $res->json('messages.0.id')) {
                return new HasilKirim(true, $res->json('messages.0.id'));
            }

            return new HasilKirim(false, null, mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 500));
        } catch (Throwable $e) {
            return new HasilKirim(false, null, mb_substr($e->getMessage(), 0, 500));
        }
    }
}
