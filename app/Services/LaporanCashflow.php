<?php

namespace App\Services;

use App\Enums\ArahMutasi;
use App\Enums\JenisMutasi;
use App\Enums\StatusMutasi;
use App\Models\Pengeluaran;
use App\Models\TabunganMutasi;
use App\Models\TahunAjaran;
use Carbon\CarbonImmutable;

/**
 * Cashflow bulanan per tahun ajaran (Juli-Juni).
 * Memisahkan (1) uang fisik masuk/keluar, (2) perpindahan titipan -> pendapatan per dana,
 * dan (3) saldo titipan wali (kewajiban pondok).
 */
class LaporanCashflow
{
    /** @return list<array<string,mixed>> satu baris per bulan */
    public function bulanan(TahunAjaran $ta): array
    {
        $baris = [];
        $saldoAwal = $this->saldoTitipan($ta->mulai->subDay()->endOfDay());
        for ($i = 0; $i < 12; $i++) {
            $awal = CarbonImmutable::parse($ta->mulai)->addMonthsNoOverflow($i)->startOfMonth();
            $akhir = $awal->endOfMonth();
            $m = TabunganMutasi::where('tabungan_mutasi.status', StatusMutasi::Terverifikasi->value)->whereBetween('tabungan_mutasi.tanggal', [$awal, $akhir]);

            $setoranTransfer = (int) (clone $m)->where('jenis', JenisMutasi::SetoranTransfer->value)->sum('nominal');
            $setoranTunai = (int) (clone $m)->where('jenis', JenisMutasi::SetoranTunai->value)->sum('nominal');
            $penarikan = (int) (clone $m)->whereIn('jenis', [JenisMutasi::PenarikanTunai->value, JenisMutasi::Pengembalian->value])->sum('nominal');
            $lainKredit = (int) (clone $m)->where('arah', ArahMutasi::Kredit->value)
                ->whereIn('jenis', [JenisMutasi::TransferDana->value, JenisMutasi::Koreksi->value, JenisMutasi::SaldoAwal->value])->sum('nominal');
            $koreksiDebit = (int) (clone $m)->where('arah', ArahMutasi::Debit->value)->where('jenis', JenisMutasi::Koreksi->value)->sum('nominal');

            $pendapatan = (clone $m)->where('tabungan_mutasi.jenis', JenisMutasi::PembayaranTagihan->value)
                ->join('tagihan', 'tagihan.id', '=', 'tabungan_mutasi.tagihan_id')
                ->join('jenis_tagihan', 'jenis_tagihan.id', '=', 'tagihan.jenis_tagihan_id')
                ->join('dana', 'dana.id', '=', 'jenis_tagihan.dana_id')
                ->groupBy('dana.kode')->selectRaw('dana.kode AS dana, SUM(tabungan_mutasi.nominal) AS total')
                ->pluck('total', 'dana')->map(fn ($v) => (int) $v)->all();

            $pengeluaran = Pengeluaran::whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])
                ->join('dana', 'dana.id', '=', 'pengeluaran.dana_id')
                ->groupBy('dana.kode')->selectRaw('dana.kode AS dana, SUM(pengeluaran.nominal) AS total')
                ->pluck('total', 'dana')->map(fn ($v) => (int) $v)->all();

            $saldoAkhir = $this->saldoTitipan($akhir);
            $baris[] = [
                'bulan' => $awal->format('Y-m'),
                'kas_bank_masuk' => ['transfer' => $setoranTransfer, 'tunai' => $setoranTunai],
                'kas_keluar_penarikan' => $penarikan,
                'pengeluaran_per_dana' => $pengeluaran,
                'pendapatan_per_dana' => $pendapatan,          // titipan -> pendapatan (tanpa uang fisik bergerak)
                'titipan_lain_masuk' => $lainKredit,            // transfer dana, koreksi, saldo awal
                'titipan_koreksi_keluar' => $koreksiDebit,
                'saldo_titipan_awal' => $saldoAwal,
                'saldo_titipan_akhir' => $saldoAkhir,
            ];
            $saldoAwal = $saldoAkhir;
        }

        return $baris;
    }

    private function saldoTitipan(CarbonImmutable $sampai): int
    {
        return (int) TabunganMutasi::where('status', StatusMutasi::Terverifikasi->value)->where('tanggal', '<=', $sampai)
            ->selectRaw('COALESCE(SUM(CASE WHEN arah = ? THEN nominal ELSE -nominal END),0) AS s', [ArahMutasi::Kredit->value])
            ->value('s');
    }
}
