<?php

namespace App\Http\Controllers;

use App\Exceptions\AturanDilanggar;
use App\Models\Pegawai;
use App\Models\Penggajian;
use App\Services\PayrollBsi;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Menu "Gaji pegawai" (izin gaji.kelola): data pegawai, penggajian per bulan, dan berkas .txt payroll BSI.
 */
class GajiController extends Controller
{
    public function __construct(private PayrollBsi $payroll) {}

    public function index()
    {
        $bulanIni = CarbonImmutable::now()->startOfMonth();

        return view('gaji.index', [
            'daftar' => Penggajian::withCount('rincian')->withSum('rincian', 'nominal')->orderByDesc('periode')->orderByDesc('id')->limit(24)->get(),
            'jumlahPegawai' => Pegawai::aktif()->count(),
            'usulanPeriode' => $bulanIni,
            'usulanTanggal' => $bulanIni->endOfMonth()->isWeekend() ? $bulanIni->endOfMonth()->previousWeekday() : $bulanIni->endOfMonth(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'periode' => 'required|date_format:Y-m',
            'tanggal_transfer' => 'required|date',
            'pesan' => 'nullable|string|max:65',
            'sumber' => 'required|in:sebelumnya,tetap',
        ]);
        if (! Pegawai::aktif()->exists()) {
            return back()->withErrors(['gaji' => 'Belum ada pegawai aktif. Isi data pegawai dulu.']);
        }
        $periode = CarbonImmutable::createFromFormat('Y-m', $d['periode'])->startOfMonth();
        $p = $this->payroll->buat($periode, CarbonImmutable::parse($d['tanggal_transfer']),
            $d['pesan'] ?: PayrollBsi::pesanBawaan($periode), $request->user(), $d['sumber'] === 'sebelumnya');

        return redirect()->route('gaji.show', $p)->with('status', 'Penggajian dibuat. Periksa nominal tiap pegawai, lalu unduh berkas BSI.');
    }

    public function show(Penggajian $penggajian)
    {
        $penggajian->load('rincian.pegawai');
        $ada = $penggajian->rincian->pluck('pegawai_id');

        return view('gaji.show', [
            'p' => $penggajian,
            'galat' => $this->payroll->periksa($penggajian),
            'bisaDitambah' => Pegawai::aktif()->whereNotIn('id', $ada)->urut()->get(),
        ]);
    }

    public function update(Request $request, Penggajian $penggajian): RedirectResponse
    {
        abort_if($penggajian->final(), 403, 'Penggajian sudah final.');
        $d = $request->validate([
            'judul' => 'required|string|max:100',
            'tanggal_transfer' => 'required|date',
            'pesan' => 'required|string|max:65',
            'rincian' => 'array',
            'rincian.*.nominal' => 'required|integer|min:0|max:999999999',
            'rincian.*.pesan' => 'nullable|string|max:65',
            'rincian.*.hapus' => 'nullable|boolean',
            'tambah' => 'nullable|array',
            'tambah.*' => ['integer', Rule::exists('pegawai', 'id')],
        ]);
        DB::transaction(function () use ($d, $penggajian) {
            $penggajian->update(['judul' => $d['judul'], 'tanggal_transfer' => $d['tanggal_transfer'], 'pesan' => PayrollBsi::bersih($d['pesan'], 65)]);
            foreach ($penggajian->rincian as $r) {
                $isi = $d['rincian'][$r->id] ?? null;
                if (! $isi) {
                    continue;
                }
                if (! empty($isi['hapus'])) {
                    $r->delete();

                    continue;
                }
                $pesan = PayrollBsi::bersih($isi['pesan'] ?? '', 65);
                $r->update(['nominal' => (int) $isi['nominal'], 'pesan' => $pesan !== '' && $pesan !== $penggajian->pesan ? $pesan : null]);
            }
            $urutan = (int) $penggajian->rincian()->max('urutan');
            foreach (Pegawai::whereIn('id', $d['tambah'] ?? [])->urut()->get() as $pg) {
                $penggajian->rincian()->firstOrCreate(['pegawai_id' => $pg->id], ['nominal' => $pg->nominal_tetap, 'urutan' => ++$urutan]);
            }
        });

        return redirect()->route('gaji.show', $penggajian)->with('status', 'Tersimpan.');
    }

    public function unduh(Penggajian $penggajian)
    {
        try {
            $isi = $this->payroll->berkas($penggajian);
        } catch (AturanDilanggar $e) {
            return redirect()->route('gaji.show', $penggajian)->withErrors(['gaji' => $e->getMessage()]);
        }

        return response($isi, 200, [
            'Content-Type' => 'text/plain; charset=us-ascii',
            'Content-Disposition' => 'attachment; filename="'.$penggajian->namaFile().'"',
        ]);
    }

    public function finalkan(Request $request, Penggajian $penggajian): RedirectResponse
    {
        try {
            $this->payroll->finalkan($penggajian, $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['gaji' => $e->getMessage()]);
        }

        return redirect()->route('gaji.show', $penggajian)->with('status', 'Penggajian ditandai sudah dikirim ke BSI dan dikunci.');
    }

    public function destroy(Penggajian $penggajian): RedirectResponse
    {
        abort_if($penggajian->final(), 403, 'Penggajian final tidak bisa dihapus.');
        $penggajian->delete();

        return redirect()->route('gaji.index')->with('status', 'Draf penggajian dihapus.');
    }

    // ------------------------------------------------------------------ pegawai

    public function pegawai(Request $request)
    {
        $semua = $request->query('tampil') === 'semua';

        return view('gaji.pegawai', [
            'pegawai' => Pegawai::when(! $semua, fn ($q) => $q->where('aktif', true))->urut()->get(),
            'semua' => $semua,
            'nonaktif' => Pegawai::where('aktif', false)->count(),
        ]);
    }

    public function simpanPegawai(Request $request): RedirectResponse
    {
        $pg = Pegawai::create($this->validasiPegawai($request) + ['urutan' => (int) Pegawai::max('urutan') + 1]);

        return redirect()->route('gaji.pegawai')->with('status', "{$pg->nama} ditambahkan.");
    }

    public function ubahPegawai(Pegawai $pegawai)
    {
        return view('gaji.pegawai-ubah', ['pg' => $pegawai]);
    }

    public function perbaruiPegawai(Request $request, Pegawai $pegawai): RedirectResponse
    {
        $pegawai->update($this->validasiPegawai($request, $pegawai) + ['aktif' => $request->boolean('aktif')]);

        return redirect()->route('gaji.pegawai')->with('status', "Data {$pegawai->nama} disimpan.");
    }

    public function imporPegawai(Request $request): RedirectResponse
    {
        $request->validate(['berkas' => 'required|file|mimes:txt|max:1024']);
        try {
            $n = $this->payroll->imporPegawai((string) file_get_contents($request->file('berkas')->getRealPath()));
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['berkas' => $e->getMessage()]);
        }

        return redirect()->route('gaji.pegawai')->with('status', "{$n['baru']} pegawai baru, {$n['diperbarui']} diperbarui dari berkas. Lengkapi nama lengkap & jabatan bila perlu.");
    }

    private function validasiPegawai(Request $request, ?Pegawai $pg = null): array
    {
        $request->merge(['no_rekening' => preg_replace('/\D/', '', (string) $request->input('no_rekening')),
            'telepon' => preg_replace('/\D/', '', (string) $request->input('telepon')) ?: null]);
        $d = $request->validate([
            'nama' => 'required|string|max:100',
            'jabatan' => 'nullable|string|max:100',
            'no_rekening' => ['required', 'digits_between:10,35', Rule::unique('pegawai', 'no_rekening')->ignore($pg?->id)],
            'nama_rekening' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:100',
            'telepon' => 'nullable|string|max:20',
            'nominal_tetap' => 'nullable|integer|min:0|max:999999999',
            'urutan' => 'nullable|integer|min:0|max:9999',
            'catatan' => 'nullable|string|max:255',
        ], [
            'no_rekening.unique' => 'Nomor rekening ini sudah dipakai pegawai lain.',
            'no_rekening.digits_between' => 'Nomor rekening harus 10–35 digit angka.',
        ]);
        $d['nama_rekening'] = PayrollBsi::bersih(($d['nama_rekening'] ?? null) ?: $d['nama'], 100);
        $d['nama'] = PayrollBsi::bersih($d['nama'], 100);
        $d['nominal_tetap'] = (int) ($d['nominal_tetap'] ?? 0);
        $d['urutan'] ??= $pg?->urutan ?? 0;

        return $d;
    }
}
