<?php

namespace App\Http\Controllers;

use App\Models\GuruMengajar;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Mapel & guru pengajar" (izin mapel.kelola, bawaan Admin):
 * daftar mata pelajaran (+KKM) dan penugasan guru ke kelas & mapel per tahun ajaran.
 * Akun guru dibuat di menu Pengguna dengan peran Guru.
 */
class MapelController extends Controller
{
    public function index(Request $request)
    {
        $daftarTa = TahunAjaran::orderByDesc('tahun_mulai')->limit(6)->get();
        $ta = $daftarTa->firstWhere('id', (int) $request->query('ta')) ?? TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());
        $ubah = $request->query('ubah') ? Mapel::find($request->query('ubah')) : null;

        return view('mapel.index', [
            'daftarTa' => $daftarTa, 'ta' => $ta, 'ubah' => $ubah,
            'mapel' => Mapel::urut()->get(),
            'kelas' => Kelas::where('aktif', true)->orderBy('tingkat')->orderBy('nama')->get(),
            'guru' => User::role('guru')->where('aktif', true)->orderBy('name')->get(),
            'penugasan' => GuruMengajar::with(['guru', 'kelas', 'mapel'])->where('tahun_ajaran_id', $ta->id)->get()
                ->sortBy(fn ($g) => sprintf('%s|%02d|%s|%s', $g->guru->name, $g->kelas->tingkat, $g->kelas->nama, $g->mapel->nama))->groupBy('user_id'),
            'taLalu' => TahunAjaran::where('tahun_mulai', $ta->tahun_mulai - 1)->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validasi($request);
        Mapel::create($d + ['aktif' => true]);

        return back()->with('status', "Mapel {$d['nama']} ditambahkan.");
    }

    public function update(Request $request, Mapel $mapel): RedirectResponse
    {
        $mapel->update($this->validasi($request, $mapel));

        return redirect()->route('mapel.index')->with('status', "Mapel {$mapel->nama} disimpan.");
    }

    public function toggle(Mapel $mapel): RedirectResponse
    {
        $mapel->update(['aktif' => ! $mapel->aktif]);

        return back()->with('status', "Mapel {$mapel->nama} ".($mapel->aktif ? 'diaktifkan.' : 'dinonaktifkan (nilai & soal tetap tersimpan).'));
    }

    public function tugaskan(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'tahun_ajaran_id' => 'required|integer|exists:tahun_ajaran,id',
            'user_id' => ['required', 'integer', Rule::in(User::role('guru')->pluck('id')->all())],
            'mapel_id' => ['required', 'integer', Rule::exists('mapel', 'id')->where('aktif', true)],
            'kelas_id' => 'required|array|min:1', 'kelas_id.*' => 'integer|exists:kelas,id',
        ], ['user_id.in' => 'Pilih akun berperan Guru.', 'kelas_id.required' => 'Pilih minimal satu kelas.']);
        $baru = 0;
        foreach ($d['kelas_id'] as $kelas) {
            $baru += (int) GuruMengajar::firstOrCreate(['user_id' => $d['user_id'], 'mapel_id' => $d['mapel_id'], 'kelas_id' => $kelas, 'tahun_ajaran_id' => $d['tahun_ajaran_id']])->wasRecentlyCreated;
        }

        return back()->with('status', "{$baru} penugasan ditambahkan".($baru < count($d['kelas_id']) ? '; sisanya sudah ada.' : '.'));
    }

    public function lepas(GuruMengajar $tugas): RedirectResponse
    {
        $tugas->delete();

        return back()->with('status', 'Penugasan dihapus. Nilai yang sudah diisi tetap tersimpan.');
    }

    /** Salin semua penugasan dari tahun ajaran sebelumnya (kelas sama), mis. saat awal tahun ajaran baru. */
    public function salin(Request $request, TahunAjaran $ta): RedirectResponse
    {
        $lalu = TahunAjaran::where('tahun_mulai', $ta->tahun_mulai - 1)->firstOrFail();
        $baru = 0;
        foreach (GuruMengajar::where('tahun_ajaran_id', $lalu->id)->get() as $g) {
            $baru += (int) GuruMengajar::firstOrCreate(['user_id' => $g->user_id, 'mapel_id' => $g->mapel_id, 'kelas_id' => $g->kelas_id, 'tahun_ajaran_id' => $ta->id])->wasRecentlyCreated;
        }

        return redirect()->route('mapel.index', ['ta' => $ta->id])->with('status', "{$baru} penugasan disalin dari {$lalu->nama}.");
    }

    private function validasi(Request $request, ?Mapel $mapel = null): array
    {
        $d = $request->validate([
            'nama' => ['required', 'string', 'max:100', Rule::unique('mapel', 'nama')->ignore($mapel?->id)],
            'kode' => 'nullable|string|max:20',
            'kkm' => 'required|integer|between:0,100',
            'urutan' => 'nullable|integer|between:0,999',
        ]);

        return array_merge($d, ['urutan' => (int) ($d['urutan'] ?? 0)]);
    }
}
