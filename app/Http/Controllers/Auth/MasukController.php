<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AkunService;
use App\Services\PendaftaranService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Satu halaman masuk untuk staf dan wali. Kolom "username" menerima username, email, atau nomor WhatsApp.
 * Password dicek lewat AkunService::cocokkanPassword agar password dari aplikasi lama (Myth/Auth) tetap dikenali.
 */
class MasukController extends Controller
{
    public function create()
    {
        return view('auth.masuk');
    }

    public function store(Request $request, AkunService $akun): RedirectResponse
    {
        $data = $request->validate(['username' => 'required|string|max:100', 'password' => 'required|string|max:200']);
        $kunci = Str::lower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            throw ValidationException::withMessages(['username' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.']);
        }

        $user = $this->cari($data['username']);
        if (! $user || ! $akun->cocokkanPassword($user, $data['password'])) {
            RateLimiter::hit($kunci, 60);
            throw ValidationException::withMessages(['username' => 'Username atau password salah.']);
        }
        if (! $user->aktif) {
            throw ValidationException::withMessages(['username' => 'Akun dinonaktifkan. Hubungi Admin Office.']);
        }

        RateLimiter::clear($kunci);
        Auth::login($user, $request->boolean('ingat'));
        $request->session()->regenerate();

        return redirect()->intended(route('beranda'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function cari(string $masukan): ?User
    {
        $masukan = trim($masukan);
        $telepon = PendaftaranService::normalTelepon($masukan);

        return User::where('username', $masukan)
            ->orWhere('email', Str::lower($masukan))
            ->when(strlen($telepon) >= 9, fn ($q) => $q->orWhere('telepon', $telepon)->orWhere('username', $telepon))
            ->first();
    }
}
