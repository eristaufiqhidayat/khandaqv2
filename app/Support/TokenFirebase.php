<?php

namespace App\Support;

use App\Exceptions\AturanDilanggar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pemeriksa Firebase ID token (hasil FirebaseAuth.currentUser.getIdToken() di aplikasi).
 * https://firebase.google.com/docs/auth/admin/verify-id-tokens#verify_id_tokens_using_a_third-party_jwt_library
 *
 * Token = JWT RS256 bertanda tangan kunci Google (securetoken). Diperiksa: tanda tangan, aud = project id,
 * iss, masa berlaku, sub, dan (untuk login Google) email terverifikasi.
 */
final class TokenFirebase
{
    public const URL_KUNCI = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    private const TOLERANSI_DETIK = 120;

    /** @var (callable(): array<string, string>)|null */
    private $kunci;

    /** @param  (callable(): array<string, string>)|null  $kunci  kid => sertifikat PEM (untuk uji; bawaan: unduh dari Google, di-cache) */
    public function __construct(private string $projectId, ?callable $kunci = null)
    {
        $this->kunci = $kunci;
    }

    /**
     * @return array<string, mixed> klaim token
     *
     * @throws AturanDilanggar
     */
    public function periksa(string $jwt): array
    {
        $bagian = explode('.', trim($jwt));
        if (count($bagian) !== 3) {
            throw new AturanDilanggar('Token Google tidak valid.');
        }
        [$h64, $p64, $s64] = $bagian;
        $header = json_decode(self::b64($h64), true);
        $klaim = json_decode(self::b64($p64), true);
        if (! is_array($header) || ! is_array($klaim) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw new AturanDilanggar('Token Google tidak valid.');
        }

        $sertifikat = ($this->kunci ? ($this->kunci)() : self::kunciGoogle())[$header['kid']] ?? null;
        if (! $sertifikat) {
            Cache::forget('firebase:kunci'); // Google memutar kunci; unduh ulang di permintaan berikutnya
            throw new AturanDilanggar('Token Google tidak dikenali. Coba masuk lagi.');
        }
        if (openssl_verify("{$h64}.{$p64}", self::b64($s64), $sertifikat, OPENSSL_ALGO_SHA256) !== 1) {
            throw new AturanDilanggar('Tanda tangan token Google tidak sah.');
        }

        $kini = time();
        $cocok = ($klaim['aud'] ?? null) === $this->projectId
            && ($klaim['iss'] ?? null) === 'https://securetoken.google.com/'.$this->projectId
            && is_string($klaim['sub'] ?? null) && $klaim['sub'] !== '' && strlen($klaim['sub']) <= 128
            && (int) ($klaim['exp'] ?? 0) > $kini - self::TOLERANSI_DETIK
            && (int) ($klaim['iat'] ?? PHP_INT_MAX) <= $kini + self::TOLERANSI_DETIK
            && (int) ($klaim['auth_time'] ?? PHP_INT_MAX) <= $kini + self::TOLERANSI_DETIK;
        if (! $cocok) {
            throw new AturanDilanggar('Token Google kedaluwarsa atau bukan untuk aplikasi ini. Coba masuk lagi.');
        }

        return $klaim;
    }

    /** @return array<string, string> */
    private static function kunciGoogle(): array
    {
        return Cache::remember('firebase:kunci', 3600, function () {
            $res = Http::timeout(10)->get(self::URL_KUNCI);
            if (! $res->successful() || ! is_array($res->json())) {
                throw new AturanDilanggar('Server tidak bisa menghubungi Google. Coba lagi sebentar lagi.');
            }

            return $res->json();
        });
    }

    private static function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
