<?php

namespace App\Http\Controllers;

use App\Exceptions\AturanDilanggar;
use App\Models\Kelas;
use App\Models\SantriKelas;
use App\Models\TahunAjaran;
use App\Services\DataSantriService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Kenaikan kelas massal di awal tahun ajaran (izin santri.kelola). Kelas yang tidak dipetakan = tinggal kelas. */
class KenaikanController extends Controller
{
    public function index(Request $request)
    {
        // Tujuan bawaan: tahun ajaran yang dimulai Juli berikutnya (dibuat bila belum ada), baru kemudian daftar pilihan dimuat.
        $sekarang = CarbonImmutable::now();
        $bawaan = TahunAjaran::untukTanggal(CarbonImmutable::create($sekarang->month >= 7 ? $sekarang->year + 1 : $sekarang->year, 7, 1));
        $daftarTa = TahunAjaran::orderByDesc('tahun_mulai')->get();
        $ke = $daftarTa->firstWhere('id', $request->integer('ke')) ?? $bawaan;
        $dari = TahunAjaran::where('tahun_mulai', $ke->tahun_mulai - 1)->first();
        $kelas = Kelas::where('aktif', true)->orderBy('tingkat')->orderBy('nama')->get();
        $jumlah = $dari ? SantriKelas::where('tahun_ajaran_id', $dari->id)->whereHas('santri', fn ($q) => $q->where('status', 'aktif'))
            ->selectRaw('kelas_id, COUNT(*) n')->groupBy('kelas_id')->pluck('n', 'kelas_id') : collect();
        $sudah = SantriKelas::where('tahun_ajaran_id', $ke->id)->count();

        // Saran kelas baru: tingkat + 1 dengan jenis yang sama (putra/putri dari kolom jenis_kelamin atau akhiran nama kelas).
        $jenis = fn (Kelas $k) => $k->jenis_kelamin ?: (preg_match('/\b(putra|putri)\b/i', $k->nama, $m) ? strtolower($m[1]) : null);
        $saran = $kelas->mapWithKeys(fn (Kelas $k) => [$k->id => $kelas->first(fn (Kelas $b) => $b->tingkat === $k->tingkat + 1 && $jenis($b) === $jenis($k))
            ?? $kelas->first(fn (Kelas $b) => $b->tingkat === $k->tingkat + 1 && $jenis($b) === null)]);

        return view('kenaikan.index', compact('daftarTa', 'ke', 'dari', 'kelas', 'jumlah', 'sudah', 'saran'));
    }

    public function store(Request $request, DataSantriService $svc): RedirectResponse
    {
        $d = $request->validate(['dari' => 'required|exists:tahun_ajaran,id', 'ke' => 'required|exists:tahun_ajaran,id', 'peta' => 'required|array']);
        $peta = [];
        foreach ($d['peta'] as $lama => $baru) {
            if ($baru === 'tinggal' || $baru === null || $baru === '') {
                continue;
            }
            $peta[(int) $lama] = $baru === 'lulus' ? null : (int) $baru;
        }
        try {
            $h = $svc->naikkanKelas(TahunAjaran::findOrFail($d['dari']), TahunAjaran::findOrFail($d['ke']), $peta, $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['kenaikan' => $e->getMessage()]);
        }

        return redirect()->route('santri.index')->with('status', "{$h['naik']} santri naik kelas, {$h['lulus']} lulus (kode transfer dibebaskan).");
    }
}
