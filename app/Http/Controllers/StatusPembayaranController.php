<?php

namespace App\Http\Controllers;

use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Services\LaporanTagihan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Menu "Status pembayaran" (izin laporan.lihat): kisi SPP Juli–Juni per santri dan sisa DSB/Daftar Ulang. */
class StatusPembayaranController extends Controller
{
    public function __invoke(Request $request, LaporanTagihan $laporan)
    {
        $hariIni = CarbonImmutable::now();
        $daftarTa = TahunAjaran::orderByDesc('mulai')->get();
        $ta = $daftarTa->firstWhere('id', (int) $request->query('ta'))
            ?? TahunAjaran::aktif() ?? TahunAjaran::untukTanggal($hariIni);
        $kelas = $request->filled('kelas') ? Kelas::find($request->integer('kelas')) : null;
        $status = in_array($request->query('status'), ['menunggak', 'lunas'], true) ? $request->query('status') : 'semua';
        $tab = $request->query('tab') === 'dsb' ? 'dsb' : 'spp';

        $bulan = [];
        for ($i = 0; $i < 12; $i++) {
            $bulan[] = CarbonImmutable::parse($ta->mulai)->addMonthsNoOverflow($i)->startOfMonth();
        }

        return view('status.index', [
            'tab' => $tab, 'ta' => $ta, 'daftarTa' => $daftarTa, 'kelas' => $kelas, 'status' => $status,
            'daftarKelas' => Kelas::orderBy('nama')->get(), 'bulan' => $bulan,
            'spp' => $tab === 'spp' ? $laporan->rekapSpp($ta, $hariIni, $kelas, $status) : [],
            'ringkasan' => $laporan->ringkasanSpp($ta, $hariIni),
            'dsbdu' => $tab === 'dsb' ? collect($laporan->rekapDsbDu())->where('sisa', '>', 0)->values() : collect(),
        ]);
    }
}
