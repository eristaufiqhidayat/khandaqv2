<?php

namespace App\Services;

use App\Contracts\Notifier;
use App\Enums\Izin;
use App\Enums\KeputusanTunggakan;
use App\Enums\StatusTagihan;
use App\Enums\TahapPeringatan;
use App\Exceptions\AturanDilanggar;
use App\Models\Peringatan;
use App\Models\Santri;
use App\Models\Tagihan;
use App\Models\User;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Tahapan warning (dijalankan harian oleh scheduler):
 *  - Pengingat: 3 hari sebelum akhir bulan, bila tagihan bulan ini belum lunas.
 *  - Terlambat 1/2/3 bulan: dihitung dari jumlah tagihan bertanda "dihitung_tunggakan" (SPP)
 *    yang sudah lewat jatuh tempo. Tidak ada denda.
 *  - Tahap 3: santri menjadi kandidat pemulangan (aturan yang sudah berjalan); surat resmi ke akun wali,
 *    wajib dikonfirmasi dibaca. Sistem TIDAK memulangkan; keputusan oleh Keuangan.
 * Idempoten: satu peringatan per santri per tahap per bulan.
 */
class PeringatanService
{
    public function __construct(private Notifier $notifier) {}

    /** @return array<string,int> jumlah peringatan baru per tahap */
    public function jalankan(?CarbonInterface $hariIni = null): array
    {
        $hariIni = CarbonImmutable::parse($hariIni ?? CarbonImmutable::now())->startOfDay();
        $hasil = [];

        foreach (Santri::aktif()->get() as $santri) {
            $tahap = $this->tahapTunggakan($santri, $hariIni);
            if ($tahap) {
                $hasil[$tahap->value] = ($hasil[$tahap->value] ?? 0) + (int) $this->catatDanKirim($santri, $tahap, $hariIni);
            }
            if ($hariIni->toDateString() === $hariIni->endOfMonth()->subDays(3)->toDateString()) {
                $hasil['pengingat'] = ($hasil['pengingat'] ?? 0) + (int) $this->pengingat($santri, $hariIni);
            }
        }

        return array_filter($hasil);
    }

    public function bulanTunggakan(Santri $santri, CarbonInterface $hariIni): int
    {
        return $this->tunggakanDihitung($santri, $hariIni)->count();
    }

    public function tahapTunggakan(Santri $santri, CarbonInterface $hariIni): ?TahapPeringatan
    {
        return TahapPeringatan::dariBulanTunggakan($this->bulanTunggakan($santri, $hariIni));
    }

    public function tandaiDibaca(Peringatan $p, User $wali): void
    {
        if (! $wali->adalahWaliDari($p->santri)) {
            throw new AturanDilanggar('Hanya wali santri ini yang dapat mengonfirmasi.');
        }
        $p->update(['dibaca_pada' => $p->dibaca_pada ?? CarbonImmutable::now()]);
    }

    /** Keputusan Keuangan atas santri di tahap 3 bulan. */
    public function putuskan(Peringatan $p, KeputusanTunggakan $keputusan, User $pemutus, ?string $catatan = null): Peringatan
    {
        Otorisasi::pastikan($pemutus, Izin::TunggakanPutuskan);
        if ($p->tahap !== TahapPeringatan::Terlambat3) {
            throw new AturanDilanggar('Keputusan hanya untuk tahap tunggakan 3 bulan.');
        }
        if ($keputusan === KeputusanTunggakan::Pemulangan && ! $p->dibaca_pada) {
            throw new AturanDilanggar('Pemberitahuan belum dikonfirmasi dibaca oleh wali.');
        }
        $p->update([
            'keputusan' => $keputusan, 'diputuskan_oleh' => $pemutus->id,
            'diputuskan_pada' => CarbonImmutable::now(), 'catatan' => $catatan,
        ]);

        return $p;
    }

    private function tunggakanDihitung(Santri $santri, CarbonInterface $hariIni)
    {
        return $santri->tunggakan($hariIni)
            ->whereHas('jenisTagihan', fn ($q) => $q->where('dihitung_tunggakan', true))
            ->orderBy('jatuh_tempo')
            ->get();
    }

    private function catatDanKirim(Santri $santri, TahapPeringatan $tahap, CarbonImmutable $hariIni): bool
    {
        $tunggakan = $this->tunggakanDihitung($santri, $hariIni);
        $p = Peringatan::firstOrCreate(
            ['santri_id' => $santri->id, 'tahap' => $tahap->value, 'bulan_acuan' => $hariIni->startOfMonth()->toDateString()],
            [
                'bulan_tunggakan' => $tunggakan->count(),
                'total_tunggakan' => $tunggakan->sum(fn (Tagihan $t) => $t->sisa()),
                'tagihan_tertua_id' => $tunggakan->first()?->id,
            ],
        );
        if (! $p->wasRecentlyCreated) {
            return false;
        }
        if ($this->notifier->kirimPeringatan($p, $this->pesan($santri, $p))) {
            $p->update(['dikirim_pada' => CarbonImmutable::now()]);
        }

        return true;
    }

    private function pengingat(Santri $santri, CarbonImmutable $hariIni): bool
    {
        $sisa = (int) $santri->tagihan()
            ->whereIn('status', StatusTagihan::terbuka())
            ->whereBetween('jatuh_tempo', [$hariIni->toDateString(), $hariIni->endOfMonth()->toDateString()])
            ->get()->sum(fn (Tagihan $t) => $t->sisa());
        if ($sisa === 0 || $santri->saldo() >= $sisa) {
            return false; // tidak ada tagihan, atau saldo cukup dan akan terpotong otomatis
        }

        return $this->catatDanKirim($santri, TahapPeringatan::Pengingat, $hariIni);
    }

    private function pesan(Santri $santri, Peringatan $p): string
    {
        $rp = 'Rp'.number_format($p->total_tunggakan, 0, ',', '.');

        return match ($p->tahap) {
            TahapPeringatan::Pengingat => "Pengingat: tagihan {$santri->nama} bulan ini jatuh tempo pada akhir bulan. Saldo tabungan belum mencukupi.",
            TahapPeringatan::Terlambat1 => "Tagihan SPP {$santri->nama} terlambat 1 bulan ({$rp}). Raport ditahan sampai tagihan lunas.",
            TahapPeringatan::Terlambat2 => "Peringatan kedua: SPP {$santri->nama} tertunggak 2 bulan ({$rp}).",
            TahapPeringatan::Terlambat3 => "PEMBERITAHUAN RESMI: SPP {$santri->nama} tertunggak {$p->bulan_tunggakan} bulan ({$rp}). Sesuai aturan pondok, santri dapat dipulangkan. Mohon konfirmasi di portal wali dan hubungi bagian Keuangan.",
        };
    }
}
