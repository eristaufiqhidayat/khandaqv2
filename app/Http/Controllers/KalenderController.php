<?php

namespace App\Http\Controllers;

use App\Models\KalenderAkademik;
use App\Models\TahunAjaran;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Menu "Kalender akademik" (izin periode.kelola): kegiatan pondok yang tampil di portal wali. */
class KalenderController extends Controller
{
    public function index(Request $request)
    {
        $daftarTa = TahunAjaran::orderByDesc('mulai')->get();
        $ta = $daftarTa->firstWhere('id', $request->integer('ta')) ?? TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());
        $kegiatan = KalenderAkademik::antara(CarbonImmutable::parse($ta->mulai), CarbonImmutable::parse($ta->selesai))->get()
            ->groupBy(fn ($k) => $k->tanggal_mulai->format('Y-m'));

        return view('kalender.index', [
            'ta' => $ta, 'daftarTa' => $daftarTa, 'kegiatan' => $kegiatan,
            'ubah' => $request->filled('ubah') ? KalenderAkademik::find($request->integer('ubah')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        KalenderAkademik::create($this->validasi($request) + ['dibuat_oleh' => $request->user()->id]);

        return back()->with('status', 'Kegiatan ditambahkan.');
    }

    public function update(Request $request, KalenderAkademik $kalender): RedirectResponse
    {
        $kalender->update($this->validasi($request));

        return redirect()->route('kalender.index', ['ta' => $request->input('ta')])->with('status', 'Kegiatan disimpan.');
    }

    public function destroy(KalenderAkademik $kalender): RedirectResponse
    {
        $kalender->delete();

        return back()->with('status', 'Kegiatan dihapus.');
    }

    private function validasi(Request $request): array
    {
        $d = $request->validate([
            'tanggal_mulai' => 'required|date', 'tanggal_selesai' => 'nullable|date|after_or_equal:tanggal_mulai',
            'kegiatan' => 'required|string|max:200', 'keterangan' => 'nullable|string|max:500',
        ], ['tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
        $d['tanggal_selesai'] = ($d['tanggal_selesai'] ?? null) === $d['tanggal_mulai'] ? null : ($d['tanggal_selesai'] ?? null);

        return $d;
    }
}
