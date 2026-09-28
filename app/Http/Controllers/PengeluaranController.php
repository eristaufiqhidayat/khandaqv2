<?php

namespace App\Http\Controllers;

use App\Exceptions\PeriodeTerkunci;
use App\Models\Akun;
use App\Models\Dana;
use App\Models\Pengeluaran;
use App\Models\Pengusul;
use App\Models\Rekening;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Menu "Pengeluaran" (izin pengeluaran.catat): pengeluaran per dana (SPP, DSB, DU, PTS, ...) dari kas/bank.
 * Pengganti enam tabel tbl_pengeluaran* lama. Bulan yang sudah tutup buku terkunci.
 */
class PengeluaranController extends Controller
{
    public function index(Request $request)
    {
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan')) ? CarbonImmutable::createFromFormat('Y-m', $request->query('bulan'))->startOfMonth() : CarbonImmutable::now()->startOfMonth();
        $danaId = $request->integer('dana') ?: null;
        $data = Pengeluaran::with(['dana', 'rekening', 'akun'])
            ->whereBetween('tanggal', [$bulan->toDateString(), $bulan->endOfMonth()->toDateString()])
            ->when($danaId, fn ($q) => $q->where('dana_id', $danaId))
            ->orderByDesc('tanggal')->orderByDesc('id')->get();

        return view('pengeluaran.index', [
            'bulan' => $bulan, 'danaId' => $danaId, 'data' => $data,
            'perDana' => $data->groupBy(fn ($p) => $p->dana->nama)->map->sum('nominal')->sortDesc(),
            'dana' => Dana::where('aktif', true)->orderBy('nama')->get(), 'rekening' => Rekening::where('aktif', true)->orderBy('kode')->get(),
            'akun' => Akun::orderBy('nama')->get(), 'pengusul' => Pengusul::orderBy('nama')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'tanggal' => 'required|date|before_or_equal:today', 'dana_id' => 'required|exists:dana,id', 'rekening_id' => 'required|exists:rekening,id',
            'akun_id' => 'nullable|exists:akun,id', 'pengusul_id' => 'nullable|exists:pengusul,id', 'nominal' => 'required|integer|min:1',
            'keterangan' => 'required|string|max:255', 'rutin' => 'nullable|boolean', 'bukti' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
        ]);
        try {
            Pengeluaran::create(collect($d)->except('bukti')->all() + [
                'rutin' => $request->boolean('rutin'), 'bukti_path' => $request->file('bukti')?->store('bukti-pengeluaran'), 'dicatat_oleh' => $request->user()->id,
            ]);
        } catch (PeriodeTerkunci $e) {
            return back()->withInput()->withErrors(['pengeluaran' => $e->getMessage()]);
        }

        return redirect()->route('pengeluaran.index', ['bulan' => substr($d['tanggal'], 0, 7)])->with('status', 'Pengeluaran Rp'.number_format($d['nominal'], 0, ',', '.').' dicatat.');
    }

    public function destroy(Pengeluaran $pengeluaran): RedirectResponse
    {
        try {
            $pengeluaran->delete();
        } catch (PeriodeTerkunci $e) {
            return back()->withErrors(['pengeluaran' => $e->getMessage()]);
        }

        return back()->with('status', 'Pengeluaran dihapus.');
    }

    public function bukti(Pengeluaran $pengeluaran)
    {
        abort_unless($pengeluaran->bukti_path && Storage::exists($pengeluaran->bukti_path), 404);

        return Storage::response($pengeluaran->bukti_path);
    }
}
