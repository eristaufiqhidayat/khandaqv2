<?php

namespace App\Http\Controllers\Wali;

use App\Enums\StatusMutasi;
use App\Http\Controllers\Controller;
use App\Models\KalenderAkademik;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Services\AksesRaport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Portal wali bergaya Khandaq v1: Menu Utama (Raport, Tabungan, Kalender, Data), tiap menu satu halaman.
 * Semua data hanya dari anak sendiri ($user->anak).
 */
class PortalController extends Controller
{
    public function __invoke(Request $request)
    {
        return view('wali.beranda', ['anak' => $this->anak($request)]);
    }

    public function raport(Request $request, AksesRaport $akses)
    {
        $sekarang = CarbonImmutable::now();
        $anak = $this->anak($request, ['raport.semester'])->map(fn ($s) => [
            'santri' => $s,
            'raport' => $s->raport->whereNotNull('diterbitkan_pada')->sortByDesc('semester_id')->map(fn ($r) => [
                'raport' => $r, 'terkunci' => $akses->alasanTerkunci($s, $r->semester, $sekarang),
            ]),
        ]);

        return view('wali.raport', ['anak' => $anak]);
    }

    public function tabungan(Request $request)
    {
        $anak = $this->anak($request)->map(fn ($s) => [
            'santri' => $s,
            'saldo' => $s->saldo(),
            'tagihan' => Tagihan::terbuka()->where('santri_id', $s->id)->orderBy('jatuh_tempo')->get(),
            'mutasi' => $s->mutasi()->where('status', '!=', StatusMutasi::Ditolak->value)
                ->orderByDesc('tanggal')->orderByDesc('id')->limit(50)->get(),
        ]);

        return view('wali.tabungan', ['anak' => $anak, 'sekarang' => CarbonImmutable::now()]);
    }

    public function kalender()
    {
        $hariIni = CarbonImmutable::today();
        $ta = TahunAjaran::aktif() ?? TahunAjaran::untukTanggal($hariIni);
        $akhir = CarbonImmutable::parse($ta->selesai)->max($hariIni->addMonths(3));
        $semua = KalenderAkademik::antara(CarbonImmutable::parse($ta->mulai), $akhir)->get();
        $selesai = fn ($k) => $k->tanggal_selesai ?? $k->tanggal_mulai;

        return view('wali.kalender', [
            'ta' => $ta, 'hariIni' => $hariIni,
            'akanDatang' => $semua->filter(fn ($k) => $selesai($k)->gte($hariIni))->groupBy(fn ($k) => $k->tanggal_mulai->format('Y-m')),
            'lewat' => $semua->filter(fn ($k) => $selesai($k)->lt($hariIni))->sortByDesc('tanggal_mulai'),
        ]);
    }

    public function data(Request $request)
    {
        $ta = TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());

        return view('wali.data', [
            'wali' => $request->user(),
            'anak' => $this->anak($request, ['riwayatKelas' => fn ($r) => $r->where('tahun_ajaran_id', $ta->id)->with('kelas')]),
        ]);
    }

    private function anak(Request $request, array $with = []): Collection
    {
        return $request->user()->anak()->with($with)->orderBy('nama')->get();
    }
}
