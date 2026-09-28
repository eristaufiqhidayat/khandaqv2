<?php

namespace App\Http\Controllers;

use App\Enums\StatusKeringanan;
use App\Exceptions\AturanDilanggar;
use App\Models\Keringanan;
use App\Services\KeringananService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Menu "Persetujuan" (izin keringanan.setujui): beasiswa SPP, diskon DSB/DU, dan dispensasi raport
 * yang diajukan Admin / Admin Office. Pengaju tidak boleh menyetujui pengajuannya sendiri.
 */
class PersetujuanController extends Controller
{
    public function __construct(private KeringananService $keringanan) {}

    public function index()
    {
        $relasi = ['santri', 'jenisTagihan', 'semester', 'pengaju', 'pemutus'];

        return view('persetujuan.index', [
            'menunggu' => Keringanan::where('status', StatusKeringanan::Diajukan->value)->with($relasi)->oldest()->get(),
            'riwayat' => Keringanan::whereIn('status', [StatusKeringanan::Disetujui->value, StatusKeringanan::Ditolak->value])
                ->with($relasi)->latest('diputuskan_pada')->limit(20)->get(),
        ]);
    }

    public function setujui(Request $request, Keringanan $keringanan): RedirectResponse
    {
        $d = $request->validate(['catatan' => 'nullable|string|max:500']);

        return $this->jalankan(fn () => $this->keringanan->setujui($keringanan, $request->user(), $d['catatan'] ?? null),
            'Disetujui: '.$this->label($keringanan).'.');
    }

    public function tolak(Request $request, Keringanan $keringanan): RedirectResponse
    {
        $d = $request->validate(['catatan' => 'required|string|max:500']);

        return $this->jalankan(fn () => $this->keringanan->tolak($keringanan, $request->user(), $d['catatan']),
            'Ditolak: '.$this->label($keringanan).'.');
    }

    public function lampiran(Keringanan $keringanan)
    {
        abort_unless($keringanan->lampiran_path && Storage::exists($keringanan->lampiran_path), 404);

        return Storage::response($keringanan->lampiran_path);
    }

    private function label(Keringanan $k): string
    {
        return str_replace('_', ' ', $k->jenis->value).' '.$k->santri->nama;
    }

    private function jalankan(callable $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['persetujuan' => $e->getMessage()]);
        }

        return redirect()->route('persetujuan.index')->with('status', $pesan);
    }
}
