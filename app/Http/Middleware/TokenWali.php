<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * API aplikasi wali: wajib "Authorization: Bearer <token>". Token harus masih berlaku, akunnya aktif,
 * dan berperan wali_santri. Tidak memakai sesi/cookie.
 */
class TokenWali
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = ApiToken::cari($request->bearerToken());
        $user = $token?->user;
        if (! $token || ! $user) {
            return response()->json(['pesan' => 'Sesi berakhir. Silakan masuk lagi.', 'kode' => 'token_tidak_sah'], 401);
        }
        if (! $user->aktif || ! $user->hasRole('wali_santri')) {
            $token->delete();

            return response()->json(['pesan' => 'Akun dinonaktifkan. Hubungi Admin Office.', 'kode' => 'akun_nonaktif'], 401);
        }
        $token->catatPemakaian();
        Auth::setUser($user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
