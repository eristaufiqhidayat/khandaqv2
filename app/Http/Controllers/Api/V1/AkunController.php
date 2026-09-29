<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AturanDilanggar;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\TahunAjaran;
use App\Services\AkunService;
use App\Services\DataWaliService;
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
