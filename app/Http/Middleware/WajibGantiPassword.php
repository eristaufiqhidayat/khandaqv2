<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pengguna dengan password awal/reset dari sistem diarahkan ke halaman ganti password
 * sebelum bisa membuka halaman lain. Daftarkan di bootstrap/app.php (web middleware).
 */
class WajibGantiPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->aktif) {
            auth()->logout();

            return redirect()->route('login')->withErrors(['username' => 'Akun dinonaktifkan. Hubungi Admin Office.']);
        }
        if ($user && $user->wajib_ganti_password && ! $request->routeIs('password.ganti', 'password.ganti.simpan', 'logout')) {
            return redirect()->route('password.ganti');
        }

        return $next($request);
    }
}
