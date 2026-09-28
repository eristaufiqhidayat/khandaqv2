<?php

namespace App\Http\Controllers;

use App\Enums\Izin;
use App\Models\Kelas;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Services\PeriodeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Tahun ajaran & kelas": aktifkan semester berjalan (izin periode.kelola) dan daftar kelas (izin kelas.kelola).
 * Tahun ajaran selalu 1 Juli – 30 Juni dan dibuat otomatis; di sini hanya menyiapkan tahun berikutnya.
 */
class PeriodeController extends Controller
{
    public function index(Request $request)
    {
        return view('periode.index', [
            'daftarTa' => TahunAjaran::with(['semester' => fn ($q) => $q->orderBy('nomor')])->orderByDesc('tahun_mulai')->limit(6)->get(),
            'kelas' => Kelas::orderBy('tingkat')->orderBy('nama')->get(),
            'bolehKelas' => $request->user()->hasPermissionTo(Izin::KelasKelola->value),
        ]);
    }

    public function aktifkan(Request $request, Semester $semester, PeriodeService $svc): RedirectResponse
    {
        $svc->aktifkan($semester, $request->user());

        return back()->with('status', "{$semester->nama} sekarang semester aktif.");
    }

    public function siapkan(): RedirectResponse
    {
        $terakhir = TahunAjaran::max('tahun_mulai') ?? CarbonImmutable::now()->year;
        $ta = TahunAjaran::untukTanggal(CarbonImmutable::create($terakhir + 1, 7, 1));

        return back()->with('status', "Tahun ajaran {$ta->nama} disiapkan. Atur tarifnya di menu Tarif.");
    }

    public function kelasStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermissionTo(Izin::KelasKelola->value), 403);
        $d = $request->validate(['nama' => 'required|string|max:20|unique:kelas,nama', 'tingkat' => 'required|integer|min:1|max:6',
            'jenis_kelamin' => ['nullable', Rule::in(['putra', 'putri'])]]);
        Kelas::create($d + ['aktif' => true]);

        return back()->with('status', "Kelas {$d['nama']} ditambahkan.");
    }

    public function kelasToggle(Request $request, Kelas $kelas): RedirectResponse
    {
        abort_unless($request->user()->hasPermissionTo(Izin::KelasKelola->value), 403);
        $kelas->update(['aktif' => ! $kelas->aktif]);

        return back()->with('status', "Kelas {$kelas->nama} ".($kelas->aktif ? 'diaktifkan.' : 'dinonaktifkan (riwayat tetap tersimpan).'));
    }
}
