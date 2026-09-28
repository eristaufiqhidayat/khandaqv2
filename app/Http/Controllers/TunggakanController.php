<?php

namespace App\Http\Controllers;

use App\Enums\KeputusanTunggakan;
use App\Enums\TahapPeringatan;
use App\Exceptions\AturanDilanggar;
use App\Models\Peringatan;
use App\Services\PeringatanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Tunggakan" (izin tunggakan.putuskan): peringatan terakhir per santri (tahap 1/2/3),
 * dan keputusan Keuangan untuk tahap 3 bulan. Pemulangan hanya bila wali sudah membaca pemberitahuan.
 */
class TunggakanController extends Controller
{
    public function index(Request $request)
    {
        // Satu baris per santri: peringatan terbaru (bulan acuan terakhir, tahap tertinggi).
        $terbaru = Peringatan::with(['santri.wali'])
            ->where('tahap', '!=', TahapPeringatan::Pengingat->value)
            ->orderByDesc('bulan_acuan')->orderByDesc('id')->get()
            ->unique('santri_id')->values();
        $filter = $request->query('filter') === 'semua' ? 'semua' : 'perlu';
        $baris = $filter === 'semua' ? $terbaru : $terbaru->filter(fn ($p) => $p->keputusan === null || $p->tahap !== TahapPeringatan::Terlambat3);

        return view('tunggakan.index', [
            'baris' => $baris->sortByDesc(fn ($p) => [$p->tahap === TahapPeringatan::Terlambat3 && ! $p->keputusan, $p->bulan_tunggakan])->values(),
            'filter' => $filter,
            'menungguKeputusan' => $terbaru->filter(fn ($p) => $p->tahap === TahapPeringatan::Terlambat3 && ! $p->keputusan)->count(),
        ]);
    }

    public function putuskan(Request $request, Peringatan $peringatan, PeringatanService $svc): RedirectResponse
    {
        $d = $request->validate([
            'keputusan' => ['required', Rule::enum(KeputusanTunggakan::class)],
            'catatan' => 'nullable|string|max:500',
        ]);
        try {
            $svc->putuskan($peringatan, KeputusanTunggakan::from($d['keputusan']), $request->user(), $d['catatan'] ?? null);
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['tunggakan' => $e->getMessage()]);
        }

        return redirect()->route('tunggakan.index')->with('status', 'Keputusan untuk '.$peringatan->santri->nama.': '.$d['keputusan'].'.');
    }
}
