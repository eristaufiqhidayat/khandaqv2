<?php

namespace App\Http\Controllers;

use App\Exceptions\AturanDilanggar;
use App\Models\User;
use App\Services\DataWaliService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ubah data wali oleh petugas (izin santri.kelola atau akun_wali.reset). Dibuka dari detail santri atau tab Wali santri. */
class WaliSantriController extends Controller
{
    public function __construct(private DataWaliService $svc) {}

    public function edit(Request $request, User $wali)
    {
        abort_unless($wali->hasRole('wali_santri'), 404);
        $wali->load(['anak' => fn ($q) => $q->orderBy('nama')]);

        return view('walisantri.ubah', ['wali' => $wali, 'kembali' => $this->kembali($request->query('kembali') ?? url()->previous()),
            'bolehPassword' => $request->user()->hasAnyPermission([\App\Enums\Izin::AkunWaliReset->value, \App\Enums\Izin::PenggunaKelola->value])]);
    }

    public function update(Request $request, User $wali): RedirectResponse
    {
        abort_unless($wali->hasRole('wali_santri'), 404);
        $d = $request->validate([
            'name' => 'required|string|max:100', 'telepon' => 'required|string|max:20',
            'email' => 'nullable|email|max:100', 'nik' => 'nullable|digits:16',
            'pekerjaan' => 'nullable|string|max:100', 'alamat' => 'nullable|string|max:300',
            'hubungan' => 'array', 'hubungan.*' => [Rule::in(['ayah', 'ibu', 'wali'])],
        ]);
        try {
            $this->svc->ubahOlehPetugas($wali, $d, $d['hubungan'] ?? [], $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withInput()->withErrors(['wali' => $e->getMessage()]);
        }

        return redirect($this->kembali($request->input('kembali')))->with('status', "Data wali {$wali->name} disimpan.");
    }

    public function password(Request $request, User $wali, \App\Services\AkunService $akun): RedirectResponse
    {
        abort_unless($wali->hasRole('wali_santri'), 404);
        $d = $request->validate(['password' => 'required|string|confirmed|max:200', 'wajib_ganti' => 'nullable|boolean'],
            ['password.confirmed' => 'Ulangi password tidak sama.']);
        try {
            $akun->aturPasswordWali($wali, $d['password'], $request->boolean('wajib_ganti'), $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }

        return redirect($this->kembali($request->input('kembali')))->with('status', "Password {$wali->name} diganti."
            .($request->boolean('wajib_ganti') ? ' Wali wajib menggantinya saat masuk.' : '').' Sesi aplikasi Android wali ini dikeluarkan.');
    }

    /** Hanya URL di aplikasi ini (cegah open redirect). */
    private function kembali(?string $url): string
    {
        $url = (string) $url;

        return $url !== '' && str_starts_with($url, url('/')) && ! str_contains($url, '/ubah') ? $url : route('pengguna.index', ['tab' => 'wali']);
    }
}
