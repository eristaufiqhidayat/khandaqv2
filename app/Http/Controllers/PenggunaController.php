<?php

namespace App\Http\Controllers;

use App\Exceptions\AturanDilanggar;
use App\Models\User;
use App\Services\AkunService;
use App\Services\Whatsapp\KirimAksesWali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Pengguna" (izin pengguna.kelola): akun staf (admin, admin office, keuangan).
 * Password awal/reset dikirim ke WhatsApp pemilik; bila WhatsApp belum aktif, reset dari server:
 * php artisan khandaq:reset-password <username>.
 */
class PenggunaController extends Controller
{
    private const PERAN = ['admin' => 'Admin', 'admin_office' => 'Admin Office', 'keuangan' => 'Keuangan'];

    public function __construct(private AkunService $akun, private KirimAksesWali $akses) {}

    public function index()
    {
        return view('pengguna.index', [
            'staf' => User::whereDoesntHave('roles', fn ($q) => $q->where('name', 'wali_santri'))->with('roles')->orderBy('name')->get(),
            'peran' => self::PERAN, 'waOn' => $this->akses->aktif(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'name' => 'required|string|max:100', 'username' => 'required|alpha_dash|max:50|unique:users,username',
            'email' => 'required|email|max:100|unique:users,email', 'telepon' => 'required|string|max:20',
            'peran' => ['required', Rule::in(array_keys(self::PERAN))],
        ]);
        try {
            ['user' => $u, 'password_awal' => $pw] = $this->akun->buatStaf($d['name'], $d['username'], $d['email'], $d['telepon'], $d['peran'], $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withInput()->withErrors(['pengguna' => $e->getMessage()]);
        }
        $kirim = $this->akses->kirim($u, $pw, 'Akun staf Khandaq untuk Anda sudah dibuat');

        return redirect()->route('pengguna.index')->with('status', "Akun {$u->name} ({$d['peran']}) dibuat. ".$kirim['pesan'].($kirim['terkirim'] ? '' : " Dari server: php artisan khandaq:reset-password {$u->username}"));
    }

    public function peran(Request $request, User $user): RedirectResponse
    {
        $d = $request->validate(['peran' => ['required', Rule::in(array_keys(self::PERAN))]]);
        if ($user->is($request->user())) {
            return back()->withErrors(['pengguna' => 'Peran akun sendiri tidak bisa diubah dari sini.']);
        }
        if ($user->aktif && $user->hasRole('admin') && $d['peran'] !== 'admin' && $this->jumlahAdminAktif() <= 1) {
            return back()->withErrors(['pengguna' => 'Harus tetap ada minimal satu Admin aktif.']);
        }
        $user->syncRoles([$d['peran']]);

        return back()->with('status', "{$user->name} sekarang ".self::PERAN[$d['peran']].'.');
    }

    public function reset(Request $request, User $user): RedirectResponse
    {
        try {
            $pw = $this->akun->resetPassword($user, $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['pengguna' => $e->getMessage()]);
        }
        $kirim = $this->akses->kirim($user, $pw, 'Password akun Khandaq Anda di-reset');

        return back()->with('status', "Password {$user->name} di-reset. ".$kirim['pesan'].($kirim['terkirim'] ? '' : " Dari server: php artisan khandaq:reset-password {$user->username}"));
    }

    public function aktif(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['pengguna' => 'Akun sendiri tidak bisa dinonaktifkan.']);
        }
        if ($user->aktif && $user->hasRole('admin') && $this->jumlahAdminAktif() <= 1) {
            return back()->withErrors(['pengguna' => 'Harus tetap ada minimal satu Admin aktif.']);
        }
        $user->update(['aktif' => ! $user->aktif]);

        return back()->with('status', "Akun {$user->name} ".($user->aktif ? 'diaktifkan.' : 'dinonaktifkan; tidak bisa masuk lagi.'));
    }

    private function jumlahAdminAktif(): int
    {
        return User::role('admin')->where('aktif', true)->count();
    }
}
