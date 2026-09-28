<?php

namespace App\Http\Controllers;

use App\Enums\StatusMutasi;
use App\Exceptions\AturanDilanggar;
use App\Models\TabunganMutasi;
use App\Models\TutupBuku;
use App\Services\TutupBukuService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Menu "Tutup buku" (izin tutup_buku): bulan ditutup berurutan; setelah ditutup, transaksi bertanggal
 * bulan itu tidak bisa ditambah, diubah, atau dihapus (juga mengunci Sinkronisasi data lama).
 */
class TutupBukuController extends Controller
{
    public function index(Request $request, TutupBukuService $svc)
    {
        $pertama = ! TutupBuku::exists();
        $bulan = $this->bulanBerikutnya();
        // Tutup buku pertama boleh mulai dari bulan mana pun (semua bulan sebelumnya ikut terkunci).
        if ($pertama && $request->filled('bulan') && preg_match('/^\d{4}-\d{2}$/', $request->query('bulan'))) {
            $bulan = CarbonImmutable::createFromFormat('Y-m', $request->query('bulan'))->startOfMonth();
        }
        $akhir = $bulan->endOfMonth();

        return view('tutupbuku.index', [
            'bulan' => $bulan, 'pertama' => $pertama, 'mode' => config('khandaq.mode'),
            'pilihanBulan' => $pertama ? collect(range(1, 18))->map(fn ($i) => CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow($i)) : collect(),
            'bolehDitutup' => $akhir->lt(CarbonImmutable::now()),
            'saldoTitipan' => $svc->saldoTitipan($akhir),
            'saldoRekening' => $svc->saldoRekening($akhir),
            'pending' => TabunganMutasi::where('status', StatusMutasi::Pending->value)->where('tanggal', '<=', $akhir)->count(),
            'riwayat' => TutupBuku::with('petugas')->orderByDesc('bulan')->limit(12)->get(),
        ]);
    }

    public function store(Request $request, TutupBukuService $svc): RedirectResponse
    {
        $d = $request->validate(['bulan' => 'required|date_format:Y-m', 'catatan' => 'nullable|string|max:500', 'konfirmasi' => 'accepted']);
        if (config('khandaq.mode') === 'paralel') {
            // Data masih ditimpa Sinkronisasi data lama; tutup buku akan menguncinya permanen.
            return back()->withErrors(['tutupbuku' => 'Tutup buku baru bisa dilakukan setelah aplikasi berpindah ke mode produksi (KHANDAQ_MODE=produksi).']);
        }
        try {
            $svc->tutup(CarbonImmutable::createFromFormat('Y-m', $d['bulan'])->startOfMonth(), $request->user(), $d['catatan'] ?? null);
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['tutupbuku' => $e->getMessage()]);
        }

        return redirect()->route('tutupbuku.index')->with('status', 'Buku '.CarbonImmutable::createFromFormat('Y-m', $d['bulan'])->translatedFormat('F Y').' ditutup.');
    }

    /** Bulan setelah tutup buku terakhir; bila belum pernah, bulan lalu. */
    private function bulanBerikutnya(): CarbonImmutable
    {
        $terakhir = TutupBuku::max('bulan');

        return $terakhir
            ? CarbonImmutable::parse($terakhir)->addMonthNoOverflow()->startOfMonth()
            : CarbonImmutable::now()->startOfMonth()->subMonthNoOverflow();
    }
}
