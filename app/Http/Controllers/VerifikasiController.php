<?php

namespace App\Http\Controllers;

use App\Enums\Izin;
use App\Enums\JenisMutasi;
use App\Enums\StatusBankMutasi;
use App\Enums\StatusMutasi;
use App\Enums\StatusSantri;
use App\Exceptions\AturanDilanggar;
use App\Models\BankMutasi;
use App\Models\Santri;
use App\Models\TabunganMutasi;
use App\Services\BankMutasiMatcher;
use App\Services\TabunganService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Menu "Verifikasi setoran" (izin setoran.verifikasi):
 * (1) laporan transfer yang belum cocok dengan mutasi BSI -> verifikasi manual / tolak;
 * (2) baris mutasi BSI yang perlu ditinjau -> terima sebagai setoran santri / abaikan.
 * Pencatat setoran tidak boleh memverifikasi setorannya sendiri (ditegakkan TabunganService).
 */
class VerifikasiController extends Controller
{
    public function index()
    {
        return view('verifikasi.index', [
            'pending' => TabunganMutasi::where('status', StatusMutasi::Pending->value)
                ->where('jenis', JenisMutasi::SetoranTransfer->value)
                ->with(['santri', 'pencatat'])->orderBy('tanggal')->get(),
            'ditinjau' => BankMutasi::where('status', StatusBankMutasi::Ditinjau->value)
                ->with('santriTerdeteksi')->orderBy('tanggal')->get(),
            'daftarSantri' => Santri::whereIn('status', [StatusSantri::Aktif->value, StatusSantri::Calon->value])
                ->orderBy('nama')->get(['id', 'nama', 'kode_unik']),
        ]);
    }

    public function verifikasi(Request $request, TabunganMutasi $mutasi, TabunganService $tabungan): RedirectResponse
    {
        return $this->jalankan(fn () => $tabungan->verifikasi($mutasi, $request->user()),
            'Setoran '.$mutasi->santri->nama.' diverifikasi dan masuk ke saldo.');
    }

    public function tolak(Request $request, TabunganMutasi $mutasi, TabunganService $tabungan): RedirectResponse
    {
        $d = $request->validate(['alasan' => 'required|string|max:200']);

        return $this->jalankan(fn () => $tabungan->tolak($mutasi, $request->user(), $d['alasan']),
            'Setoran '.$mutasi->santri->nama.' ditolak.');
    }

    public function terima(Request $request, BankMutasi $bank, BankMutasiMatcher $matcher): RedirectResponse
    {
        $d = $request->validate(['santri_id' => 'required|exists:santri,id']);
        $santri = Santri::findOrFail($d['santri_id']);

        return $this->jalankan(fn () => $matcher->terimaSebagaiSetoran($bank, $santri, $request->user()),
            'Mutasi '.$bank->no_referensi.' diterima sebagai setoran '.$santri->nama.'.');
    }

    public function abaikan(Request $request, BankMutasi $bank, BankMutasiMatcher $matcher): RedirectResponse
    {
        $d = $request->validate(['catatan' => 'required|string|max:200']);

        return $this->jalankan(fn () => $matcher->abaikan($bank, $request->user(), $d['catatan']),
            'Mutasi '.$bank->no_referensi.' ditandai bukan setoran.');
    }

    /** Bukti transfer disimpan di disk privat; hanya pencatat & verifikator yang boleh melihat. */
    public function bukti(Request $request, TabunganMutasi $mutasi)
    {
        $u = $request->user();
        abort_unless($u->hasPermissionTo(Izin::SetoranVerifikasi->value) || $u->hasPermissionTo(Izin::SetoranCatat->value), 403);
        abort_unless($mutasi->bukti_path && Storage::exists($mutasi->bukti_path), 404);

        return Storage::response($mutasi->bukti_path);
    }

    private function jalankan(callable $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['verifikasi' => $e->getMessage()]);
        }

        return redirect()->route('verifikasi.index')->with('status', $pesan);
    }
}
