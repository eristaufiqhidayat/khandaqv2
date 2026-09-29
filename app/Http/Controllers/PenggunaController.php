<?php

namespace App\Http\Controllers;

use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\AkunService;
use App\Services\Whatsapp\KirimAksesWali;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Pengguna", dua tab:
 *  - Staf (izin pengguna.kelola): akun admin, admin office, keuangan.
 *  - Wali santri (terlihat oleh pengguna.kelola ATAU akun_wali.reset): daftar semua akun wali beserta anaknya.
 *    Reset password & nonaktifkan akun wali hanya untuk pemegang akun_wali.reset (bawaan: Admin Office).
 * Password awal/reset dikirim ke WhatsApp pemilik; bila WhatsApp belum aktif, reset dari server:
 * php artisan khandaq:reset-password <username>.
 */
class PenggunaController extends Controller
{
    private const PERAN = ['admin' => 'Admin', 'admin_office' => 'Admin Office', 'keuangan' => 'Keuangan'];

    public function __construct(private AkunService $akun, private KirimAksesWali $akses) {}

    public function index(Request $request)
    {
        $saya = $request->user();
        $bolehStaf = $saya->hasPermissionTo(Izin::PenggunaKelola->value);
        $tab = $bolehStaf && $request->query('tab') !== 'wali' ? 'staf' : 'wali';
        $data = [
            'tab' => $tab, 'bolehStaf' => $bolehStaf, 'bolehKelolaWali' => $saya->hasPermissionTo(Izin::AkunWaliReset->value),
            'bolehUbahWali' => $saya->hasAnyPermission([Izin::PenggunaKelola->value, Izin::SantriKelola->value, Izin::AkunWaliReset->value]),
            'peran' => self::PERAN, 'waOn' => $this->akses->aktif(),
        ];
        if ($tab === 'staf') {
            $data['staf'] = User::whereDoesntHave('roles', fn ($q) => $q->where('name', 'wali_santri'))->with('roles')->orderBy('name')->get();
        } else {
            $data += $this->daftarWali($request);
        }

        return view('pengguna.index', $data);
    }

    private const STATUS_WALI = ['semua' => 'Semua', 'aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'tanpa_wa' => 'Tanpa nomor WA'];

    private function daftarWali(Request $request): array
    {
        $q = trim((string) $request->query('q'));
        $status = array_key_exists($s = (string) $request->query('status'), self::STATUS_WALI) ? $s : 'semua';
        $ta = TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());
        $tanpaWa = fn ($w) => $w->where(fn ($x) => $x->whereNull('telepon')->orWhere('telepon', ''));

        $wali = User::role('wali_santri')
            ->with(['anak' => fn ($a) => $a->orderBy('nama')->with(['riwayatKelas' => fn ($r) => $r->where('tahun_ajaran_id', $ta->id)->with('kelas')])])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('username', 'like', "%{$q}%")
                ->orWhere('telepon', 'like', '%'.(preg_replace('/\D/', '', $q) ?: $q).'%')
                ->orWhereHas('anak', fn ($s) => $s->where('nama', 'like', "%{$q}%")->orWhere('nis', $q))))
            ->when($status === 'aktif', fn ($w) => $w->where('aktif', true))
            ->when($status === 'nonaktif', fn ($w) => $w->where('aktif', false))
            ->when($status === 'tanpa_wa', $tanpaWa)
            ->orderBy('name')->paginate(30)->withQueryString();

        $semua = User::role('wali_santri');
        $jumlah = [
            'semua' => (clone $semua)->count(),
            'aktif' => (clone $semua)->where('aktif', true)->count(),
            'nonaktif' => (clone $semua)->where('aktif', false)->count(),
            'tanpa_wa' => $tanpaWa(clone $semua)->count(),
        ];

        return ['wali' => $wali, 'q' => $q, 'status' => $status, 'statusWali' => self::STATUS_WALI, 'jumlah' => $jumlah,
            'bolehLihatSantri' => $request->user()->hasPermissionTo(Izin::SantriKelola->value)];
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
        $this->stafSaja($user);
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
        $this->stafSaja($user);
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
        $this->stafSaja($user);
        if ($user->is($request->user())) {
            return back()->withErrors(['pengguna' => 'Akun sendiri tidak bisa dinonaktifkan.']);
        }
        if ($user->aktif && $user->hasRole('admin') && $this->jumlahAdminAktif() <= 1) {
            return back()->withErrors(['pengguna' => 'Harus tetap ada minimal satu Admin aktif.']);
        }
        $user->update(['aktif' => ! $user->aktif]);

        return back()->with('status', "Akun {$user->name} ".($user->aktif ? 'diaktifkan.' : 'dinonaktifkan; tidak bisa masuk lagi.'));
    }

    // ------------------------------------------------------------------ tab wali (izin akun_wali.reset)

    public function waliReset(Request $request, User $user): RedirectResponse
    {
        $this->waliSaja($user);
        try {
            $pw = $this->akun->resetPassword($user, $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['pengguna' => $e->getMessage()]);
        }
        $kirim = $this->akses->kirim($user, $pw, 'Password akun portal wali Anda di-reset');

        return back()->with('status', "Password {$user->name} di-reset. ".$kirim['pesan']
            .($kirim['terkirim'] || ! $user->username ? '' : " Dari server: php artisan khandaq:reset-password {$user->username}"));
    }

    public function waliAktif(Request $request, User $user): RedirectResponse
    {
        $this->waliSaja($user);
        $user->update(['aktif' => ! $user->aktif]);

        return back()->with('status', "Akun wali {$user->name} ".($user->aktif ? 'diaktifkan.' : 'dinonaktifkan; tidak bisa masuk portal lagi.'));
    }

    /** Aksi tab Staf tidak boleh dipakai untuk akun wali (mis. mengubah wali menjadi admin). */
    private function stafSaja(User $user): void
    {
        abort_if($user->hasRole('wali_santri'), 404);
    }

    private function waliSaja(User $user): void
    {
        abort_unless($user->hasRole('wali_santri'), 404);
    }

    private function jumlahAdminAktif(): int
    {
        return User::role('admin')->where('aktif', true)->count();
    }
}
