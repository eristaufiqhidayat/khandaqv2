<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusMutasi;
use App\Exceptions\AturanDilanggar;
use App\Http\Controllers\Controller;
use App\Models\KalenderAkademik;
use App\Models\Raport;
use App\Models\Rekening;
use App\Models\Santri;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Services\AksesRaport;
use App\Services\TabunganService;
use App\Support\LaporTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Data per anak untuk aplikasi wali. Setiap aksi memastikan santri adalah anak dari wali yang login (bila bukan: 404). */
class AnakController extends Controller
{
    public function show(Request $request, Santri $santri): JsonResponse
    {
        $this->pastikanAnak($request, $santri);

        return response()->json(['santri' => Format::santriLengkap($request->user()->anak()->whereKey($santri->id)->first())]);
    }

    public function foto(Request $request, Santri $santri)
    {
        $this->pastikanAnak($request, $santri);
        abort_unless($santri->foto && Storage::disk('local')->exists($santri->foto), 404);

        return Storage::disk('local')->response($santri->foto, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function tabungan(Request $request, Santri $santri): JsonResponse
    {
        $this->pastikanAnak($request, $santri);
        $hariIni = CarbonImmutable::today();
        $terverifikasi = $santri->mutasi()->where('status', StatusMutasi::Terverifikasi->value);
        $mutasi = $santri->mutasi()->where('status', '!=', StatusMutasi::Ditolak->value)
            ->orderByDesc('tanggal')->orderByDesc('id')->paginate(30, ['*'], 'halaman');
        $bsi = Rekening::where('kode', 'BSI')->first();

        return response()->json([
            'saldo' => $santri->saldo(),
            'total_masuk' => (int) (clone $terverifikasi)->where('arah', 'kredit')->sum('nominal'),
            'total_keluar' => (int) (clone $terverifikasi)->where('arah', 'debit')->sum('nominal'),
            'menunggu_verifikasi' => (int) $santri->mutasi()->where('status', StatusMutasi::Pending->value)->sum('nominal'),
            'rekening_transfer' => $bsi ? ['bank' => $bsi->bank, 'nomor' => $bsi->nomor, 'atas_nama' => $bsi->nama] : null,
            'kode_transfer' => $santri->kode_unik,
            'tagihan' => Tagihan::terbuka()->where('santri_id', $santri->id)->orderBy('jatuh_tempo')->get()
                ->map(fn ($t) => Format::tagihan($t, $hariIni))->values(),
            'mutasi' => [
                'data' => collect($mutasi->items())->map(fn ($m) => Format::mutasi($m))->values(),
                'halaman' => $mutasi->currentPage(), 'halaman_terakhir' => $mutasi->lastPage(), 'total' => $mutasi->total(),
            ],
        ]);
    }

    public function raport(Request $request, Santri $santri, AksesRaport $akses): JsonResponse
    {
        $this->pastikanAnak($request, $santri);
        $sekarang = CarbonImmutable::now();
        $daftar = $santri->raport()->with('semester')->whereNotNull('diterbitkan_pada')->orderByDesc('semester_id')->get()
            ->map(function (Raport $r) use ($akses, $santri, $sekarang) {
                $alasan = $akses->alasanTerkunci($santri, $r->semester, $sekarang);

                return [
                    'id' => $r->id, 'jenis' => $r->jenis, 'jenis_label' => Format::JENIS_RAPORT[$r->jenis] ?? strtoupper($r->jenis),
                    'semester' => $r->semester->label, 'terkunci' => (bool) $alasan, 'alasan_terkunci' => $alasan ?: null,
                    'pdf_url' => $alasan ? null : route('api.v1.anak.raport.pdf', [$santri, $r]),
                ];
            })->values();

        return response()->json(['raport' => $daftar]);
    }

    public function raportPdf(Request $request, Santri $santri, Raport $raport, AksesRaport $akses)
    {
        $this->pastikanAnak($request, $santri);
        abort_unless($raport->santri_id === $santri->id && $raport->diterbitkan_pada, 404);
        if ($alasan = $akses->alasanTerkunci($santri, $raport->semester, CarbonImmutable::now())) {
            return response()->json(['pesan' => $alasan, 'kode' => 'raport_tertahan'], 403);
        }
        abort_unless(Storage::exists($raport->file_path), 404);

        return Storage::response($raport->file_path, 'raport-'.$raport->jenis.'-'.Str::slug($santri->nama).'.pdf');
    }

    public function laporTransfer(Request $request, Santri $santri, TabunganService $tabungan): JsonResponse
    {
        $this->pastikanAnak($request, $santri);
        $d = $request->validate(LaporTransfer::aturan(), LaporTransfer::pesan());
        $path = $request->file('bukti')->store('bukti-setoran');
        try {
            $m = $tabungan->catatSetoran($santri, (int) $d['nominal'], CarbonImmutable::parse($d['tanggal']), true, $request->user(), $path,
                LaporTransfer::keterangan($d['catatan'] ?? null));
        } catch (AturanDilanggar $e) {
            Storage::delete($path);

            return response()->json(['pesan' => $e->getMessage()], 422);
        }

        return response()->json([
            'pesan' => 'Laporan transfer diterima dan menunggu verifikasi pondok. Saldo bertambah setelah diverifikasi.',
            'mutasi' => Format::mutasi($m),
        ], 201);
    }

    public function kalender(): JsonResponse
    {
        $ta = TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());
        $akhir = CarbonImmutable::parse($ta->selesai)->max(CarbonImmutable::today()->addMonths(3));

        return response()->json([
            'tahun_ajaran' => $ta->nama,
            'kegiatan' => KalenderAkademik::antara(CarbonImmutable::parse($ta->mulai), $akhir)->get()->map(fn ($k) => Format::kegiatan($k))->values(),
        ]);
    }

    private function pastikanAnak(Request $request, Santri $santri): void
    {
        abort_unless($request->user()->adalahWaliDari($santri), 404);
    }
}
