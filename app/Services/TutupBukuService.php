<?php

namespace App\Services;

use App\Enums\ArahMutasi;
use App\Enums\Izin;
use App\Enums\StatusMutasi;
use App\Exceptions\AturanDilanggar;
use App\Models\Pengeluaran;
use App\Models\Rekening;
use App\Models\TabunganMutasi;
use App\Models\TutupBuku;
use App\Models\User;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Tutup buku bulanan oleh Keuangan. Setelah ditutup, transaksi bulan itu terkunci. */
class TutupBukuService
{
    public function tutup(CarbonInterface $bulan, User $petugas, ?string $catatan = null, ?CarbonInterface $saatIni = null): TutupBuku
    {
        Otorisasi::pastikan($petugas, Izin::TutupBuku);
        $awal = CarbonImmutable::parse($bulan)->startOfMonth();
        $akhir = $awal->endOfMonth();
        $saatIni = CarbonImmutable::parse($saatIni ?? CarbonImmutable::now());

        if ($akhir->greaterThanOrEqualTo($saatIni)) {
            throw new AturanDilanggar('Bulan berjalan belum bisa ditutup.');
        }
        $terakhir = TutupBuku::max('bulan');
        if ($terakhir && ! CarbonImmutable::parse($terakhir)->addMonthNoOverflow()->startOfMonth()->equalTo($awal)) {
            throw new AturanDilanggar('Tutup buku harus berurutan; bulan berikutnya adalah '.CarbonImmutable::parse($terakhir)->addMonthNoOverflow()->format('Y-m').'.');
        }
        $pending = TabunganMutasi::where('status', StatusMutasi::Pending->value)->where('tanggal', '<=', $akhir)->count();
        if ($pending > 0) {
            throw new AturanDilanggar("Masih ada {$pending} setoran pending s.d. bulan ini; verifikasi atau tolak dulu.");
        }

        return TutupBuku::create([
            'bulan' => $awal->toDateString(),
            'saldo_titipan' => $this->saldoTitipan($akhir),
            'saldo_rekening' => $this->saldoRekening($akhir),
            'ditutup_oleh' => $petugas->id,
            'catatan' => $catatan,
        ]);
    }

    public function saldoTitipan(CarbonInterface $sampai): int
    {
        return (int) TabunganMutasi::where('status', StatusMutasi::Terverifikasi->value)
            ->where('tanggal', '<=', $sampai)
            ->selectRaw('COALESCE(SUM(CASE WHEN arah = ? THEN nominal ELSE -nominal END),0) AS s', [ArahMutasi::Kredit->value])
            ->value('s');
    }

    /** Pergerakan uang fisik menurut sistem, per rekening (untuk dicocokkan dengan saldo bank & kas fisik). */
    public function saldoRekening(CarbonInterface $sampai): array
    {
        $hasil = [];
        foreach (Rekening::all() as $r) {
            $mutasi = (int) TabunganMutasi::where('rekening_id', $r->id)
                ->where('status', StatusMutasi::Terverifikasi->value)->where('tanggal', '<=', $sampai)
                ->selectRaw('COALESCE(SUM(CASE WHEN arah = ? THEN nominal ELSE -nominal END),0) AS s', [ArahMutasi::Kredit->value])
                ->value('s');
            $keluar = (int) Pengeluaran::where('rekening_id', $r->id)->where('tanggal', '<=', $sampai)->sum('nominal');
            $hasil[$r->kode] = $mutasi - $keluar;
        }

        return $hasil;
    }
}
