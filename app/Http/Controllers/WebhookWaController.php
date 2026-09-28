<?php

namespace App\Http\Controllers;

use App\Services\Whatsapp\WebhookWaService;
use Illuminate\Http\Request;

/**
 * URL publik untuk gateway (tanpa login, dikecualikan dari CSRF):
 *   GET|POST /webhook/wa/meta      -> daftarkan di Meta App > WhatsApp > Configuration
 *   POST     /webhook/wa/ngirimwa  -> ?kunci=WA_WEBHOOK_KUNCI (ngirimwa tidak menandatangani request)
 */
class WebhookWaController extends Controller
{
    public function verifikasiMeta(Request $r)
    {
        $challenge = WebhookWaService::verifikasiMeta(
            $r->query('hub_mode'), $r->query('hub_verify_token'), $r->query('hub_challenge'),
            (string) config('khandaq.whatsapp.meta.verify_token'),
        );

        return $challenge !== null ? response($challenge, 200) : response('forbidden', 403);
    }

    public function meta(Request $r, WebhookWaService $svc)
    {
        abort_unless(WebhookWaService::tandaSah($r->getContent(), $r->header('X-Hub-Signature-256'), (string) config('khandaq.whatsapp.meta.app_secret')), 403);
        $svc->terima('meta', $r->json()->all());

        return response()->noContent(); // Meta mengulang kiriman bila tidak dijawab 200 dengan cepat
    }

    public function ngirimwa(Request $r, WebhookWaService $svc)
    {
        $kunci = (string) config('khandaq.whatsapp.webhook_kunci');
        abort_unless($kunci !== '' && hash_equals($kunci, (string) $r->query('kunci')), 403);
        $svc->terima('ngirimwa', $r->all());

        return response()->noContent();
    }
}
