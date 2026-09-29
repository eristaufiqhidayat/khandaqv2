<?php

namespace App\Services;

use App\Enums\StatusTagihan;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Santri;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Menu "Status pembayaran": daftar per santri untuk SPP (kisi Juli-Juni) dan DSB/Daftar Ulang (sisa cicilan).
 * Juga sumber angka kartu "SPP lunas" & "Tunggakan" di Ringkasan keuangan.
 */
class LaporanTagihan
{
    /** Status sel kisi bulan. */
    public const LUNAS = 'lunas', SEBAGIAN = 'sebagian', TERLAMBAT = 'terlambat', BELUM_JATUH_TEMPO = 'belum', TANPA_TAGIHAN = '-';

    /**
     * @param  'semua'|'menunggak'|'lunas'  $status
     * @return list<array{santri: Santri, kelas: ?string, bulan: array<string,string>, bulan_terlambat: int, sisa_terlambat: int}>
     */
    public function rekapSpp(TahunAjaran $ta, CarbonInterface $hariIni, ?Kelas $kelas = null, string $status = 'semua'): array
    {
        $spp = JenisTagihan::kode(JenisTagihan::SPP);
        $hari = CarbonImmutable::parse($hariIni)->toDateString();
        $bulanList = [];
        for ($i = 0; $i < 12; $i++) {
            $bulanList[] = CarbonImmutable::parse($ta->mulai)->addMonthsNoOverflow($i)->startOfMonth()->toDateString();
        }

        $santriList = Santri::aktif()
            ->with(['riwayatKelas' => fn ($q) => $q->where('tahun_ajaran_id', $ta->id)->with('kelas')])
            ->when($kelas, fn ($q) => $q->whereHas('riwayatKelas', fn ($r) => $r->where('tahun_ajaran_id', $ta->id)->where('kelas_id', $kelas->id)))
            ->orderBy('nama')->get();

        $tagihan = Tagihan::where('jenis_tagihan_id', $spp->id)->where('tahun_ajaran_id', $ta->id)
            ->whereIn('santri_id', $santriList->pluck('id'))
            ->where('status', '!=', StatusTagihan::Dibatalkan->value)
            ->get()->groupBy('santri_id');

        $hasil = [];
        foreach ($santriList as $s) {
            $sel = array_fill_keys($bulanList, self::TANPA_TAGIHAN);
            $terlambat = 0;
            $sisa = 0;
            foreach ($tagihan->get($s->id, collect()) as $t) {
                $k = $t->periode?->toDateString();
                if (! $k || ! array_key_exists($k, $sel)) {
                    continue;
                }
                $lewat = $t->jatuh_tempo->toDateString() < $hari;
                $lunas = $t->status === StatusTagihan::Lunas || $t->sisa() === 0;
                $sel[$k] = match (true) {
                    $lunas => self::LUNAS,
                    $lewat => self::TERLAMBAT,
                    $t->status === StatusTagihan::Sebagian => self::SEBAGIAN,
                    default => self::BELUM_JATUH_TEMPO,
                };
                if ($lewat && ! $lunas) {
                    $terlambat++;
                    $sisa += $t->sisa();
                }
            }
            if (($status === 'menunggak' && $terlambat === 0) || ($status === 'lunas' && $terlambat > 0)) {
                continue;
            }
            $hasil[] = [
                'santri' => $s, 'kelas' => $s->riwayatKelas->first()?->kelas?->nama,
                'bulan' => $sel, 'bulan_terlambat' => $terlambat, 'sisa_terlambat' => $sisa,
            ];
        }

        // Yang menunggak paling lama di atas.
        usort($hasil, fn ($a, $b) => [$b['bulan_terlambat'], $a['santri']->nama] <=> [$a['bulan_terlambat'], $b['santri']->nama]);

        return $hasil;
    }

    /**
     * Ringkasan untuk kartu di Ringkasan keuangan: berapa santri berbayar yang lunas s.d. jatuh tempo terakhir.
     *
     * @return array{berbayar:int, lunas:int, persen:float, menunggak:int, total_tunggakan:int}
     */
    public function ringkasanSpp(TahunAjaran $ta, CarbonInterface $hariIni): array
    {
        $rows = array_filter($this->rekapSpp($ta, $hariIni), fn ($r) => in_array(true, array_map(fn ($v) => $v !== self::TANPA_TAGIHAN, $r['bulan']), true));
        $menunggak = array_filter($rows, fn ($r) => $r['bulan_terlambat'] > 0);
        $n = count($rows);

        return [
            'berbayar' => $n, 'lunas' => $n - count($menunggak),
            'persen' => $n ? round(($n - count($menunggak)) / $n * 100, 1) : 100.0,
            'menunggak' => count($menunggak),
            'total_tunggakan' => array_sum(array_column($menunggak, 'sisa_terlambat')),
        ];
    }

    /** DSB & Daftar Ulang yang belum lunas (semua tahun ajaran), urut sisa terbesar. */
    public function rekapDsbDu(?TahunAjaran $ta = null): array
    {
        $jenis = JenisTagihan::whereIn('kode', [JenisTagihan::DSB, JenisTagihan::DU])->pluck('kode', 'id');

        return Tagihan::whereIn('jenis_tagihan_id', $jenis->keys())
            ->when($ta, fn ($q) => $q->where('tahun_ajaran_id', $ta->id))
            ->where('status', '!=', StatusTagihan::Dibatalkan->value)
            ->with('santri')->get()
            ->map(fn (Tagihan $t) => [
                'santri' => $t->santri, 'jenis' => $jenis[$t->jenis_tagihan_id], 'nominal' => $t->nominal,
                'diskon' => $t->potongan, 'terbayar' => $t->terbayar, 'sisa' => $t->sisa(),
                'jatuh_tempo' => $t->jatuh_tempo->toDateString(), 'status' => $t->status->value,
            ])
            ->sortByDesc('sisa')->values()->all();
    }
}
