<?php

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Models\CatatanHarianGuru;
use App\Models\KalenderAkademik;
use App\Models\SoalPg;
use App\Services\NilaiService;
use App\Support\KonteksGuru;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Menu "Dashboard guru": kelas yang diajar, kemajuan input nilai, catatan terakhir, kegiatan pondok terdekat. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, NilaiService $svc)
    {
        $guru = $request->user();
        $k = KonteksGuru::dari($request);
        $hari = CarbonImmutable::today();

        $kelas = $k->penugasan->map(function ($g) use ($svc, $k) {
            $r = $svc->rekap($g->kelas_id, $g->mapel, $k->semester)['ringkas'];

            return ['tugas' => $g, 'r' => $r];
        });

        return view('guru.dashboard', [
            'k' => $k, 'kelas' => $kelas,
            'jumlahSantri' => $kelas->unique(fn ($x) => $x['tugas']->kelas_id)->sum(fn ($x) => $x['r']['jumlah']),
            'catatanBulanIni' => CatatanHarianGuru::where('user_id', $guru->id)->whereBetween('tanggal', [$hari->startOfMonth()->toDateString(), $hari->endOfMonth()->toDateString()])->count(),
            'catatanHariIni' => CatatanHarianGuru::where('user_id', $guru->id)->whereDate('tanggal', $hari->toDateString())->count(),
            'catatanTerakhir' => CatatanHarianGuru::with(['kelas', 'mapel'])->where('user_id', $guru->id)->orderByDesc('tanggal')->orderByDesc('id')->limit(5)->get(),
            'soalSaya' => SoalPg::where('dibuat_oleh', $guru->id)->count(),
            'soalBank' => SoalPg::whereIn('mapel_id', $k->penugasan->pluck('mapel_id')->unique())->count(),
            'kegiatan' => KalenderAkademik::antara($hari, $hari->addDays(45))->limit(5)->get(),
        ]);
    }
}
