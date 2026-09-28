<?php

namespace App\Http\Controllers;

use App\Models\Peringatan;
use App\Models\Santri;
use App\Models\TabunganMutasi;
use App\Models\TahunAjaran;
use App\Enums\StatusMutasi;
use App\Services\LaporanCashflow;
use App\Services\LaporanTagihan;
use Carbon\CarbonImmutable;

/** Menu "Ringkasan keuangan" (izin laporan.lihat). */
class RingkasanController extends Controller
{
    public function __invoke(LaporanTagihan $tagihan, LaporanCashflow $cashflow)
    {
        $hariIni = CarbonImmutable::now();
        $ta = TahunAjaran::aktif() ?? TahunAjaran::untukTanggal($hariIni);
        $bulanIni = collect($cashflow->bulanan($ta))->firstWhere('bulan', $hariIni->format('Y-m'));

        return view('beranda.ringkasan', [
            'ta' => $ta,
            'spp' => $tagihan->ringkasanSpp($ta, $hariIni),
            'dsbdu' => collect($tagihan->rekapDsbDu($ta))->where('sisa', '>', 0),
            'bulanIni' => $bulanIni,
            'saldoTitipan' => $bulanIni['saldo_titipan_akhir'] ?? 0,
            'santriAktif' => Santri::aktif()->count(),
            'menungguVerifikasi' => TabunganMutasi::where('status', StatusMutasi::Pending->value)->count(),
            'tahap3' => Peringatan::where('tahap', 'terlambat_3')->whereNull('keputusan')->count(),
        ]);
    }
}
