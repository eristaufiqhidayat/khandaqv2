<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AturanDilanggar;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\TahunAjaran;
use App\Services\AkunService;
use App\Models\User;
use App\Services\DataWaliService;
use App\Support\TokenFirebase;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Login aplikasi Android wali, profil wali, ganti password. */
class AkunController extends Controller
{
    public function masuk(Request $request, AkunService $akun): JsonResponse
    {
        $d = $request->validate([
            'username' => 'required|string|max:100', 'password' => 'required|string|max:200', 'perangkat' => 'nullable|string|max:100',
        ]);
        $kunci = 'api|'.Str::lower($d['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            return response()->json(['pesan' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.', 'kode' => 'terlalu_banyak'], 429);
        }
        $user = $akun->cariUntukMasuk($d['username']);
        if (! $user || ! $akun->cocokkanPassword($user, $d['password'])) {
            RateLimiter::hit($kunci, 60);

            return response()->json(['pesan' => 'Username atau password salah.', 'kode' => 'salah'], 422);
        }
        if (! $user->hasRole('wali_santri')) {
            return response()->json(['pesan' => 'Aplikasi ini khusus wali santri. Staf silakan masuk lewat web Khandaq.', 'kode' => 'bukan_wali'], 403);
        }
        if (! $user->aktif) {
            return response()->json(['pesan' => 'Akun dinonaktifkan. Hubungi Admin Office.', 'kode' => 'akun_nonaktif'], 403);
        }
        RateLimiter::clear($kunci);
        [$token, $teks] = ApiToken::terbitkan($user, $d['perangkat'] ?? null);

        return response()->json(['token' => $teks, 'kedaluwarsa_pada' => $token->kedaluwarsa_pada->toIso8601String()] + $this->profil($request->setUserResolver(fn () => $user)));
    }

    /**
     * Masuk dengan Google: aplikasi mengirim Firebase ID token. Wali dicari lewat akun Google yang pernah
     * ditautkan, lalu lewat email Google yang sama dengan email wali di Khandaq.
     */
    public function masukGoogle(Request $request): JsonResponse
    {
        $d = $request->validate(['id_token' => 'required|string|max:4096', 'perangkat' => 'nullable|string|max:100']);
        $kunci = 'api-google|'.$request->ip();
        if (RateLimiter::tooManyAttempts($kunci, 10)) {
            return response()->json(['pesan' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.', 'kode' => 'terlalu_banyak'], 429);
        }
        RateLimiter::hit($kunci, 60);
        $klaim = $this->klaimGoogle($d['id_token']);
        if ($klaim instanceof JsonResponse) {
            return $klaim;
        }
        $email = Str::lower((string) $klaim['email']);
        $user = User::where('firebase_uid', $klaim['sub'])->first();
        if (! $user) {
            $kandidat = User::role('wali_santri')->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email])->orWhereRaw('LOWER(email_google) = ?', [$email]))->get();
            if ($kandidat->count() > 1) {
                return response()->json(['pesan' => "Email {$email} terdaftar di lebih dari satu akun wali. Hubungi Admin Office.", 'kode' => 'email_ganda'], 409);
            }
            $user = $kandidat->first();
            if (! $user) {
                return response()->json(['pesan' => "Akun Google {$email} belum terhubung ke akun wali. Masuk sekali dengan username & password, "
                    .'lalu buka Profil > Hubungkan akun Google. Atau minta Admin Office mengisi email ini di data wali.', 'kode' => 'google_belum_terhubung'], 404);
            }
            $user->forceFill(['firebase_uid' => $klaim['sub'], 'email_google' => $email])->save();
        }
        if (! $user->hasRole('wali_santri')) {
            return response()->json(['pesan' => 'Aplikasi ini khusus wali santri. Staf silakan masuk lewat web Khandaq.', 'kode' => 'bukan_wali'], 403);
        }
        if (! $user->aktif) {
            return response()->json(['pesan' => 'Akun dinonaktifkan. Hubungi Admin Office.', 'kode' => 'akun_nonaktif'], 403);
        }
        RateLimiter::clear($kunci);
        [$token, $teks] = ApiToken::terbitkan($user, $d['perangkat'] ?? null);
        $profil = $this->profil($request->setUserResolver(fn () => $user));
        // Masuk lewat Google tidak memakai password, jadi tidak dipaksa mengganti password awal.
        $profil['wali']['wajib_ganti_password'] = false;

        return response()->json(['token' => $teks, 'kedaluwarsa_pada' => $token->kedaluwarsa_pada->toIso8601String()] + $profil);
    }

    /** Wali yang sudah masuk menautkan akun Google-nya (email Google boleh beda dengan email di Khandaq). */
    public function tautkanGoogle(Request $request): JsonResponse
    {
        $d = $request->validate(['id_token' => 'required|string|max:4096']);
        $klaim = $this->klaimGoogle($d['id_token']);
        if ($klaim instanceof JsonResponse) {
            return $klaim;
        }
        $lain = User::where('firebase_uid', $klaim['sub'])->whereKeyNot($request->user()->id)->exists();
        if ($lain) {
            return response()->json(['pesan' => 'Akun Google ini sudah terhubung ke akun wali lain.', 'kode' => 'google_dipakai'], 409);
        }
        $request->user()->forceFill(['firebase_uid' => $klaim['sub'], 'email_google' => Str::lower((string) $klaim['email'])])->save();

        return response()->json(['pesan' => 'Akun Google '.$klaim['email'].' terhubung. Berikutnya bisa masuk dengan tombol Google.'] + $this->profil($request));
    }

    public function lepasGoogle(Request $request): JsonResponse
    {
        $request->user()->forceFill(['firebase_uid' => null, 'email_google' => null])->save();

        return response()->json(['pesan' => 'Akun Google dilepas.'] + $this->profil($request));
    }

    /** @return array<string, mixed>|JsonResponse */
    private function klaimGoogle(string $idToken): array|JsonResponse
    {
        $project = (string) config('khandaq.firebase_project_id');
        if ($project === '') {
            return response()->json(['pesan' => 'Masuk dengan Google belum diaktifkan di server.', 'kode' => 'google_nonaktif'], 503);
        }
        try {
            $klaim = app(TokenFirebase::class, ['projectId' => $project])->periksa($idToken);
        } catch (AturanDilanggar $e) {
            return response()->json(['pesan' => $e->getMessage(), 'kode' => 'token_google_salah'], 422);
        }
        if (($klaim['firebase']['sign_in_provider'] ?? null) !== 'google.com' || empty($klaim['email']) || ($klaim['email_verified'] ?? false) !== true) {
            return response()->json(['pesan' => 'Hanya akun Google dengan email terverifikasi yang bisa dipakai.', 'kode' => 'token_google_salah'], 422);
        }

        return $klaim;
    }

    public function keluar(Request $request): JsonResponse
    {
        $request->attributes->get('api_token')?->delete();

        return response()->json(['pesan' => 'Berhasil keluar.']);
    }

    public function saya(Request $request): JsonResponse
    {
        return response()->json($this->profil($request));
    }

    public function ubah(Request $request, DataWaliService $svc): JsonResponse
    {
        $d = $request->validate([
            'alamat' => 'nullable|string|max:300', 'pekerjaan' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:100', 'telepon' => 'nullable|string|max:20',
        ]);
        try {
            $wali = $svc->ubahProfil($request->user(), $d);
        } catch (AturanDilanggar $e) {
            return response()->json(['pesan' => $e->getMessage()], 422);
        }
        $pesan = 'Profil disimpan.'.($wali->telepon_menunggu ? " Nomor WhatsApp baru {$wali->telepon_menunggu} menunggu verifikasi pondok." : '');

        return response()->json(['pesan' => $pesan] + $this->profil($request));
    }

    public function gantiPassword(Request $request, AkunService $akun): JsonResponse
    {
        $d = $request->validate(['password_lama' => 'required|string', 'password_baru' => 'required|string|confirmed|max:200']);
        try {
            $akun->gantiPassword($request->user(), $d['password_lama'], $d['password_baru']);
        } catch (AturanDilanggar $e) {
            return response()->json(['pesan' => $e->getMessage()], 422);
        }
        // Perangkat lain harus masuk ulang dengan password baru.
        $ini = $request->attributes->get('api_token');
        ApiToken::where('user_id', $request->user()->id)->whereKeyNot($ini?->id)->delete();

        return response()->json(['pesan' => 'Password diganti.']);
    }

    private function profil(Request $request): array
    {
        $wali = $request->user()->fresh();
        $ta = TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());

        return [
            'wali' => Format::wali($wali),
            'anak' => $wali->anak()->orderBy('nama')->get()->map(fn ($s) => Format::santriRingkas($s, $ta))->values(),
        ];
    }
}
