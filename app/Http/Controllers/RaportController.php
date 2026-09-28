<?php

namespace App\Http\Controllers;

use App\Enums\Izin;
use App\Enums\StatusSantri;
use App\Exceptions\AturanDilanggar;
use App\Models\Kelas;
use App\Models\Raport;
use App\Models\Santri;
use App\Models\Semester;
use App\Services\AksesRaport;
use App\Services\KeringananService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Menu "Raport & dispensasi" (izin raport.unggah): unggah raport PDF per santri, terbitkan ke wali,
 * lihat mana yang tertahan karena SPP, dan ajukan dispensasi (izin keringanan.ajukan; disetujui Keuangan).
 * File raport disimpan di disk privat; wali hanya bisa membuka milik anaknya bila tidak tertahan (RaportPolicy).
 */
class RaportController extends Controller
{
    public function index(Request $request, AksesRaport $akses)
    {
        // Hanya semester yang sudah berjalan, atau yang sudah punya raport; tahun ajaran masa depan tidak ditampilkan.
        $daftarSemester = Semester::with('tahunAjaran')
            ->where(fn ($q) => $q->where('mulai', '<=', CarbonImmutable::now()->toDateString())->orWhereHas('raport'))
            ->orderByDesc('mulai')->get();
        $semester = $daftarSemester->firstWhere('id', $request->integer('semester'))
            ?? $daftarSemester->firstWhere('aktif', true) ?? $daftarSemester->first();
        $jenis = in_array($request->query('jenis'), ['pts', 'pas', 'pat'], true) ? $request->query('jenis') : 'pts';
        $kelasId = $request->integer('kelas') ?: null;
        $sekarang = CarbonImmutable::now();

        $baris = collect();
        if ($semester) {
            $santri = Santri::where('status', StatusSantri::Aktif->value)
                ->whereHas('riwayatKelas', fn ($r) => $r->where('tahun_ajaran_id', $semester->tahun_ajaran_id)->when($kelasId, fn ($x) => $x->where('kelas_id', $kelasId)))
                ->with(['riwayatKelas' => fn ($r) => $r->where('tahun_ajaran_id', $semester->tahun_ajaran_id)->with('kelas')])
                ->orderBy('nama')->get();
            $raport = Raport::where('semester_id', $semester->id)->where('jenis', $jenis)->get()->keyBy('santri_id');
            $baris = $santri->map(fn (Santri $s) => [
                'santri' => $s, 'kelas' => $s->riwayatKelas->first()?->kelas?->nama,
                'raport' => $raport->get($s->id),
                'terkunci' => $akses->alasanTerkunci($s, $semester, $sekarang),
            ]);
        }

        return view('raport.index', [
            'semester' => $semester, 'daftarSemester' => $daftarSemester, 'jenis' => $jenis, 'kelasId' => $kelasId,
            'daftarKelas' => Kelas::orderBy('nama')->get(), 'baris' => $baris,
            'bolehAjukan' => $request->user()->hasPermissionTo(Izin::KeringananAjukan->value),
        ]);
    }

    public function unggah(Request $request, Santri $santri): RedirectResponse
    {
        $d = $request->validate([
            'semester_id' => 'required|exists:semester,id', 'jenis' => ['required', Rule::in(['pts', 'pas', 'pat'])],
            'file' => 'required|file|mimes:pdf|max:10240', 'terbitkan' => 'nullable|boolean',
        ]);
        $lama = Raport::where(['santri_id' => $santri->id, 'semester_id' => $d['semester_id'], 'jenis' => $d['jenis']])->first();
        $path = $request->file('file')->store("raport/{$d['semester_id']}");
        Raport::updateOrCreate(
            ['santri_id' => $santri->id, 'semester_id' => $d['semester_id'], 'jenis' => $d['jenis']],
            ['file_path' => $path, 'mime' => 'application/pdf', 'diunggah_oleh' => $request->user()->id,
                'diterbitkan_pada' => $request->boolean('terbitkan') ? CarbonImmutable::now() : $lama?->diterbitkan_pada],
        );
        if ($lama && $lama->file_path !== $path) {
            Storage::delete($lama->file_path);
        }

        return back()->with('status', 'Raport '.strtoupper($d['jenis']).' '.$santri->nama.' diunggah'.($request->boolean('terbitkan') ? ' dan diterbitkan.' : '. Belum terlihat wali sampai diterbitkan.'));
    }

    public function terbitkan(Request $request, Raport $raport): RedirectResponse
    {
        $raport->update(['diterbitkan_pada' => $raport->diterbitkan_pada ? null : CarbonImmutable::now()]);

        return back()->with('status', 'Raport '.$raport->santri->nama.($raport->diterbitkan_pada ? ' diterbitkan ke wali.' : ' ditarik dari portal wali.'));
    }

    /** Dipakai staf (raport.lihat_semua) dan wali (anak sendiri, tidak tertahan) lewat RaportPolicy. */
    public function lihat(Raport $raport)
    {
        \Illuminate\Support\Facades\Gate::authorize('view', $raport);
        abort_unless(Storage::exists($raport->file_path), 404);

        return Storage::response($raport->file_path, 'raport-'.$raport->jenis.'-'.\Illuminate\Support\Str::slug($raport->santri->nama).'.pdf');
    }

    public function dispensasi(Request $request, Santri $santri, KeringananService $keringanan): RedirectResponse
    {
        $d = $request->validate(['semester_id' => 'required|exists:semester,id', 'janji_bayar' => 'required|date|after_or_equal:today', 'alasan' => 'required|string|max:500']);
        try {
            $keringanan->ajukanDispensasiRaport($santri, Semester::findOrFail($d['semester_id']), CarbonImmutable::parse($d['janji_bayar']), $d['alasan'], $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['raport' => $e->getMessage()]);
        }

        return back()->with('status', "Dispensasi raport {$santri->nama} diajukan ke Keuangan.");
    }
}
