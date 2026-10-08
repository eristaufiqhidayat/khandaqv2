<?php

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Models\GuruMengajar;
use App\Models\Mapel;
use App\Models\SoalPg;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Soal pilihan ganda": bank soal per mapel & tingkat kelas.
 * Guru melihat soal semua guru untuk mapel yang ia ajar (bank bersama), tetapi hanya mengubah/menghapus soal buatannya.
 * Paket soal bisa dicetak (lembar soal & kunci jawaban).
 */
class SoalController extends Controller
{
    public function index(Request $request)
    {
        [$mapel, $tingkat] = $this->pilihan($request->user());
        $ubah = $request->query('ubah') ? SoalPg::where('dibuat_oleh', $request->user()->id)->find($request->query('ubah')) : null;
        $soal = $this->saring($request, $mapel)->with(['mapel', 'pembuat'])->latest('id')->paginate(20)->withQueryString();

        return view('guru.soal', [
            'mapel' => $mapel, 'tingkat' => $tingkat, 'soal' => $soal, 'ubah' => $ubah, 'f' => $this->filter($request),
            'kesulitan' => SoalPg::KESULITAN, 'topik' => SoalPg::whereIn('mapel_id', $mapel->pluck('id'))->whereNotNull('topik')->distinct()->orderBy('topik')->pluck('topik'),
        ]);
    }

    public function cetak(Request $request)
    {
        [$mapel] = $this->pilihan($request->user());
        $soal = $this->saring($request, $mapel)->with('mapel')->orderBy('id')->limit(200)->get();
        if ($request->boolean('acak')) {
            $soal = $soal->shuffle(crc32((string) $request->query('kode', 'A')));
        }

        return view('guru.soal-cetak', ['soal' => $soal, 'f' => $this->filter($request), 'kunci' => $request->boolean('kunci'),
            'mapelNama' => $mapel->firstWhere('id', (int) $request->query('mapel'))?->nama, 'judul' => trim((string) $request->query('judul'))]);
    }

    public function store(Request $request): RedirectResponse
    {
        $s = new SoalPg(['dibuat_oleh' => $request->user()->id]);
        $this->isi($request, $s)->save();

        return redirect()->route('guru.soal.index', ['mapel' => $s->mapel_id, 'tingkat' => $s->tingkat])->with('status', 'Soal ditambahkan ke bank soal.');
    }

    public function update(Request $request, SoalPg $soal): RedirectResponse
    {
        abort_unless($soal->dibuat_oleh === $request->user()->id, 403);
        $this->isi($request, $soal)->save();

        return redirect()->route('guru.soal.index', ['mapel' => $soal->mapel_id, 'tingkat' => $soal->tingkat])->with('status', 'Soal diperbarui.');
    }

    public function destroy(Request $request, SoalPg $soal): RedirectResponse
    {
        abort_unless($soal->dibuat_oleh === $request->user()->id, 403);
        $soal->delete();

        return back()->with('status', 'Soal dihapus.');
    }

    /** Mapel yang diajar guru (semua tahun ajaran yang tercatat, terbaru dulu) dan tingkat kelasnya. */
    private function pilihan(User $guru): array
    {
        $tugas = GuruMengajar::with(['mapel', 'kelas'])->where('user_id', $guru->id)->get();
        $mapel = $tugas->pluck('mapel')->unique('id')->sortBy(fn (Mapel $m) => sprintf('%05d|%s', $m->urutan, $m->nama))->values();
        $tingkat = $tugas->pluck('kelas.tingkat')->unique()->sort()->values();

        return [$mapel, $tingkat->isEmpty() ? collect(range(1, 6)) : $tingkat];
    }

    private function filter(Request $request): array
    {
        return ['mapel' => (int) $request->query('mapel') ?: null, 'tingkat' => (int) $request->query('tingkat') ?: null,
            'topik' => trim((string) $request->query('topik')), 'kesulitan' => (string) $request->query('kesulitan'),
            'q' => trim((string) $request->query('q')), 'saya' => $request->boolean('saya')];
    }

    private function saring(Request $request, $mapel)
    {
        $f = $this->filter($request);

        return SoalPg::whereIn('mapel_id', $mapel->pluck('id'))
            ->when($f['mapel'], fn ($q, $v) => $q->where('mapel_id', $v))
            ->when($f['tingkat'], fn ($q, $v) => $q->where('tingkat', $v))
            ->when($f['topik'] !== '', fn ($q) => $q->where('topik', $f['topik']))
            ->when(array_key_exists($f['kesulitan'], SoalPg::KESULITAN), fn ($q) => $q->where('kesulitan', $f['kesulitan']))
            ->when($f['q'] !== '', fn ($q) => $q->where('pertanyaan', 'like', '%'.$f['q'].'%'))
            ->when($f['saya'], fn ($q) => $q->where('dibuat_oleh', $request->user()->id));
    }

    private function isi(Request $request, SoalPg $s): SoalPg
    {
        [$mapel] = $this->pilihan($request->user());
        $d = $request->validate([
            'mapel_id' => ['required', 'integer', Rule::in($mapel->pluck('id')->all())],
            'tingkat' => 'required|integer|between:1,6',
            'topik' => 'nullable|string|max:100',
            'kesulitan' => ['required', Rule::in(array_keys(SoalPg::KESULITAN))],
            'pertanyaan' => 'required|string|max:5000',
            'opsi_a' => 'required|string|max:1000', 'opsi_b' => 'required|string|max:1000',
            'opsi_c' => 'required|string|max:1000', 'opsi_d' => 'required|string|max:1000',
            'opsi_e' => 'nullable|string|max:1000',
            'jawaban' => ['required', Rule::in(SoalPg::OPSI)],
            'pembahasan' => 'nullable|string|max:5000',
        ], ['mapel_id.in' => 'Pilih mapel yang Anda ajar.']);
        if ($d['jawaban'] === 'e' && blank($d['opsi_e'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['jawaban' => 'Jawaban E dipilih tetapi opsi E kosong.']);
        }

        return $s->fill($d);
    }
}
