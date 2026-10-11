<?php

namespace App\Http\Controllers;

use App\Enums\StatusSantri;
use App\Exceptions\AturanDilanggar;
use App\Exceptions\PeriodeTerkunci;
use App\Models\Santri;
use App\Models\Tagihan;
use App\Models\TutupBuku;
use App\Services\TabunganService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Menu "Kasir santri" (izin setoran.catat): cari santri, lalu setor tunai, catat transfer,
 * tarik uang saku (izin penarikan.catat), atau bayar tagihan dari saldo (izin tagihan.kelola).
 * Semua aturan (saldo tidak boleh minus, batas harian, cicilan) ditegakkan TabunganService.
 */
class KasirController extends Controller
{
    public function __construct(private TabunganService $tabungan) {}

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $hasil = $q === '' ? collect() : Santri::query()
            ->whereIn('status', [StatusSantri::Aktif->value, StatusSantri::Calon->value])
            ->where(fn ($w) => $w->where('nama', 'like', "%{$q}%")->orWhere('nis', $q)->orWhere('kode_unik', $q))
            ->orderBy('nama')->limit(20)->get();

        $santri = $request->filled('santri') ? Santri::find($request->integer('santri')) : ($hasil->count() === 1 ? $hasil->first() : null);

        return view('kasir.index', [
            'q' => $q, 'hasil' => $hasil, 'santri' => $santri,
            'saldo' => $santri?->saldo(),
            'tagihan' => $santri ? Tagihan::terbuka()->where('santri_id', $santri->id)->with('jenisTagihan')->orderBy('jatuh_tempo')->get() : collect(),
            'mutasi' => $santri ? $santri->mutasi()->with('pencatat')->latest('tanggal')->latest('id')->limit(15)->get() : collect(),
            'hariIni' => CarbonImmutable::now(),
            'batasTerkunci' => TutupBuku::batasTerkunci(),
        ]);
    }

    public function setor(Request $request, Santri $santri): RedirectResponse
    {
        $d = $request->validate([
            'nominal' => 'required|integer|min:1000',
            'cara' => 'required|in:tunai,transfer',
            'bukti' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
            'keterangan' => 'nullable|string|max:200',
            'tanggal' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ], ['tanggal.before_or_equal' => 'Tanggal setoran tidak boleh melewati hari ini.']);
        $tanggal = $this->tanggalSetoran($d['tanggal'] ?? null);
        $bukti = $request->file('bukti')?->store('bukti-setoran');
        $padaTanggal = $tanggal->isToday() ? '' : ' (tanggal '.$tanggal->translatedFormat('d M Y').')';

        return $this->jalankan($santri, fn () => $this->tabungan->catatSetoran(
            $santri, (int) $d['nominal'], $tanggal, $d['cara'] === 'transfer', $request->user(), $bukti, $d['keterangan'] ?? null,
        ), $d['cara'] === 'transfer'
            ? 'Transfer '.$this->rp($d['nominal']).$padaTanggal.' dicatat dan menunggu verifikasi.'
            : 'Setoran tunai '.$this->rp($d['nominal']).$padaTanggal.' masuk ke saldo.');
    }

    /**
     * Tanggal setoran dari form. Kosong/hari ini = saat ini. Tanggal lampau (setoran yang baru dicatat belakangan)
     * memakai jam saat pencatatan pada tanggal itu, agar urutan riwayat di hari tersebut tetap wajar.
     */
    private function tanggalSetoran(?string $tanggal): CarbonImmutable
    {
        $sekarang = CarbonImmutable::now();
        if ($tanggal === null || $tanggal === $sekarang->toDateString()) {
            return $sekarang;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $tanggal)->setTimeFrom($sekarang);
    }

    public function tarik(Request $request, Santri $santri): RedirectResponse
    {
        $d = $request->validate(['nominal' => 'required|integer|min:1000', 'keterangan' => 'nullable|string|max:200']);

        return $this->jalankan($santri, fn () => $this->tabungan->tarikTunai(
            $santri, (int) $d['nominal'], CarbonImmutable::now(), $request->user(), $d['keterangan'] ?? null,
        ), 'Uang saku '.$this->rp($d['nominal']).' diserahkan.');
    }

    public function bayar(Request $request, Santri $santri, Tagihan $tagihan): RedirectResponse
    {
        abort_unless($tagihan->santri_id === $santri->id, 404);
        $d = $request->validate(['nominal' => 'required|integer|min:1']);

        return $this->jalankan($santri, fn () => $this->tabungan->bayarTagihan(
            $tagihan, (int) $d['nominal'], CarbonImmutable::now(), $request->user(),
        ), $tagihan->keterangan.' dibayar '.$this->rp($d['nominal']).' dari saldo.');
    }

    private function jalankan(Santri $santri, callable $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AturanDilanggar|PeriodeTerkunci $e) {
            return back()->withInput()->withErrors(['kasir' => $e->getMessage()]);
        }

        return redirect()->route('kasir.index', ['santri' => $santri->id])->with('status', $pesan);
    }

    private function rp(int|string $n): string
    {
        return 'Rp'.number_format((int) $n, 0, ',', '.');
    }
}
