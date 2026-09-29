<?php

namespace App\Http\Controllers\Wali;

use App\Exceptions\AturanDilanggar;
use App\Http\Controllers\Controller;
use App\Models\Rekening;
use App\Models\Santri;
use App\Services\TabunganService;
use App\Support\LaporTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Portal wali: lapor transfer dengan foto bukti. Masuk ke Verifikasi setoran sebagai pending. */
class LaporTransferController extends Controller
{
    public function create(Request $request)
    {
        return view('wali.lapor', [
            'anak' => $request->user()->anak()->orderBy('nama')->get(),
            'pilih' => $request->integer('anak') ?: null,
            'bsi' => Rekening::where('kode', 'BSI')->first(),
        ]);
    }

    public function store(Request $request, TabunganService $tabungan): RedirectResponse
    {
        $d = $request->validate(LaporTransfer::aturan() + ['santri_id' => 'required|integer'], LaporTransfer::pesan());
        $santri = Santri::find($d['santri_id']);
        abort_unless($santri && $request->user()->adalahWaliDari($santri), 404);
        $path = $request->file('bukti')->store('bukti-setoran');
        try {
            $tabungan->catatSetoran($santri, (int) $d['nominal'], CarbonImmutable::parse($d['tanggal']), true, $request->user(), $path,
                LaporTransfer::keterangan($d['catatan'] ?? null));
        } catch (AturanDilanggar $e) {
            Storage::delete($path);

            return back()->withInput()->withErrors(['lapor' => $e->getMessage()]);
        }

        return redirect()->route('wali.tabungan')->with('status', 'Laporan transfer untuk '.$santri->nama.' diterima dan menunggu verifikasi pondok. Saldo bertambah setelah diverifikasi.');
    }
}
