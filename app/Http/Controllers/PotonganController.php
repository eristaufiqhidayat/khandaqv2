<?php

namespace App\Http\Controllers;

use App\Enums\StatusSantri;
use App\Exceptions\AturanDilanggar;
use App\Models\JedaPotongan;
use App\Models\JenisTagihan;
use App\Models\PengecualianPotongan;
use App\Models\Santri;
use App\Services\PotonganOtomatisService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Menu "Potongan otomatis" (izin pengecualian.kelola): opsi "tidak dijalankan" untuk infak, laundry, kesehatan,
 * per santri (mis. tidak ikut laundry) atau massal sementara (mis. libur panjang).
 */
class PotonganController extends Controller
{
    public function __construct(private PotonganOtomatisService $svc) {}

    public function index()
    {
        $hariIni = CarbonImmutable::now()->toDateString();

        return view('potongan.index', [
            'jenis' => JenisTagihan::where('potong_otomatis', true)->where('aktif', true)->orderBy('urutan_alokasi')->get(),
            'pengecualian' => PengecualianPotongan::with(['santri', 'jenisTagihan'])
                ->where(fn ($q) => $q->whereNull('selesai')->orWhere('selesai', '>=', $hariIni))->latest()->get(),
            'jeda' => JedaPotongan::with('jenisTagihan')->where('selesai', '>=', $hariIni)->orderBy('mulai')->get(),
            'daftarSantri' => Santri::where('status', StatusSantri::Aktif->value)->orderBy('nama')->get(['id', 'nama', 'nis']),
        ]);
    }

    public function kecualikan(Request $request): RedirectResponse
    {
        $d = $request->validate(['santri_id' => 'required|exists:santri,id', 'jenis_tagihan_id' => 'required|exists:jenis_tagihan,id',
            'mulai' => 'required|date', 'selesai' => 'nullable|date|after_or_equal:mulai', 'alasan' => 'required|string|max:255']);

        return $this->jalankan(fn () => $this->svc->kecualikan(Santri::findOrFail($d['santri_id']), JenisTagihan::findOrFail($d['jenis_tagihan_id']),
            CarbonImmutable::parse($d['mulai']), isset($d['selesai']) ? CarbonImmutable::parse($d['selesai']) : null, $d['alasan'], $request->user()),
            'Pengecualian disimpan. Tagihan dalam rentang itu yang belum dibayar dibatalkan.');
    }

    public function jeda(Request $request): RedirectResponse
    {
        $d = $request->validate(['jenis_tagihan_id' => 'required|exists:jenis_tagihan,id', 'mulai' => 'required|date',
            'selesai' => 'required|date|after_or_equal:mulai', 'alasan' => 'required|string|max:255']);

        return $this->jalankan(fn () => $this->svc->jeda(JenisTagihan::findOrFail($d['jenis_tagihan_id']),
            CarbonImmutable::parse($d['mulai']), CarbonImmutable::parse($d['selesai']), $d['alasan'], $request->user()),
            'Jeda massal disimpan untuk semua santri.');
    }

    /** Akhiri pengecualian (santri ikut lagi mulai hari ini). */
    public function akhiri(PengecualianPotongan $pengecualian): RedirectResponse
    {
        $pengecualian->update(['selesai' => CarbonImmutable::now()->subDay()->toDateString()]);

        return back()->with('status', 'Pengecualian '.$pengecualian->santri->nama.' diakhiri; potongan berjalan lagi mulai hari ini.');
    }

    private function jalankan(callable $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AturanDilanggar $e) {
            return back()->withInput()->withErrors(['potongan' => $e->getMessage()]);
        }

        return redirect()->route('potongan.index')->with('status', $pesan);
    }
}
