<?php

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Models\CatatanHarianGuru;
use App\Models\GuruMengajar;
use App\Support\KonteksGuru;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Menu "Catatan harian guru": jurnal mengajar per hari. Setiap guru hanya melihat & mengubah catatannya sendiri. */
class CatatanHarianController extends Controller
{
    public function index(Request $request)
    {
        $guru = $request->user();
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan')) ? CarbonImmutable::parse($request->query('bulan').'-01') : CarbonImmutable::now()->startOfMonth();
        $k = KonteksGuru::dari($request);
        $ubah = $request->query('ubah') ? CatatanHarianGuru::where('user_id', $guru->id)->find($request->query('ubah')) : null;

        $catatan = CatatanHarianGuru::with(['kelas', 'mapel'])->where('user_id', $guru->id)
            ->whereBetween('tanggal', [$bulan->toDateString(), $bulan->endOfMonth()->toDateString()])
            ->when($request->query('kelas'), fn ($q, $v) => $q->where('kelas_id', $v))
            ->orderByDesc('tanggal')->orderByDesc('id')->get();

        return view('guru.catatan', [
            'k' => $k, 'bulan' => $bulan, 'catatan' => $catatan->groupBy(fn ($c) => $c->tanggal->toDateString()),
            'jumlah' => $catatan->count(), 'ubah' => $ubah, 'filterKelas' => (int) $request->query('kelas'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $c = new CatatanHarianGuru(['user_id' => $request->user()->id]);
        $this->isi($request, $c)->save();

        return redirect()->route('guru.catatan.index', ['bulan' => $c->tanggal->format('Y-m')])->with('status', 'Catatan '.$c->tanggal->translatedFormat('d F Y').' disimpan.');
    }

    public function update(Request $request, CatatanHarianGuru $catatan): RedirectResponse
    {
        abort_unless($catatan->user_id === $request->user()->id, 403);
        $this->isi($request, $catatan)->save();

        return redirect()->route('guru.catatan.index', ['bulan' => $catatan->tanggal->format('Y-m')])->with('status', 'Catatan diperbarui.');
    }

    public function destroy(Request $request, CatatanHarianGuru $catatan): RedirectResponse
    {
        abort_unless($catatan->user_id === $request->user()->id, 403);
        $catatan->delete();

        return back()->with('status', 'Catatan dihapus.');
    }

    private function isi(Request $request, CatatanHarianGuru $c): CatatanHarianGuru
    {
        $d = $request->validate([
            'tanggal' => 'required|date|before_or_equal:today',
            'penugasan' => 'required|integer',
            'materi' => 'required|string|max:200',
            'kegiatan' => 'nullable|string|max:5000',
            'tidak_hadir' => 'nullable|string|max:255',
            'kendala' => 'nullable|string|max:5000',
            'tindak_lanjut' => 'nullable|string|max:5000',
        ], ['tanggal.before_or_equal' => 'Tanggal catatan tidak boleh di masa depan.']);
        $tugas = GuruMengajar::with('kelas')->where('user_id', $request->user()->id)->find($d['penugasan']);
        abort_unless($tugas, 422, 'Pilih kelas & mapel yang Anda ajar.');

        return $c->fill([
            'tanggal' => $d['tanggal'], 'kelas_id' => $tugas->kelas_id, 'kelas_nama' => $tugas->kelas->nama, 'mapel_id' => $tugas->mapel_id,
            'materi' => $d['materi'], 'kegiatan' => $d['kegiatan'] ?? null, 'tidak_hadir' => $d['tidak_hadir'] ?? null,
            'kendala' => $d['kendala'] ?? null, 'tindak_lanjut' => $d['tindak_lanjut'] ?? null,
        ]);
    }
}
