<?php

namespace App\Services;

use App\Enums\Izin;
use App\Models\Raport;
use App\Models\Santri;
use App\Models\Semester;
use App\Models\Tagihan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Aturan raport terkunci:
 * - staf dengan izin raport.lihat_semua selalu boleh;
 * - wali hanya untuk anaknya sendiri, dan hanya raport yang sudah diterbitkan;
 * - terkunci bila ada tagihan "wajib lunas untuk raport" (SPP) yang sudah lewat jatuh tempo dan belum lunas,
 *   kecuali ada dispensasi raport yang disetujui Keuangan untuk semester itu.
 * Dipakai oleh App\Policies\RaportPolicy.
 */
class AksesRaport
{
    public function __construct(private KeringananService $keringanan) {}

    public function bolehLihat(User $user, Raport $raport, ?CarbonInterface $saatIni = null): bool
    {
        $saatIni ??= CarbonImmutable::now();
        if ($user->hasPermissionTo(Izin::RaportLihatSemua->value)) {
            return true;
        }
        if (! $user->adalahWaliDari($raport->santri)) {
            return false;
        }
        if (! $raport->diterbitkan_pada || $raport->diterbitkan_pada->greaterThan($saatIni)) {
            return false;
        }

        return $this->alasanTerkunci($raport->santri, $raport->semester, $saatIni) === null;
    }

    /** Pesan untuk wali bila raport tertahan, atau null bila terbuka. */
    public function alasanTerkunci(Santri $santri, Semester $semester, ?CarbonInterface $saatIni = null): ?string
    {
        $saatIni ??= CarbonImmutable::now();
        $tunggakan = $this->tunggakanPenahan($santri, $saatIni);
        if ($tunggakan->isEmpty()) {
            return null;
        }
        if ($this->keringanan->dispensasiRaport($santri, $semester)) {
            return null;
        }
        $daftar = $tunggakan->map(fn (Tagihan $t) => $t->keterangan)->implode(', ');
        $total = number_format($tunggakan->sum(fn (Tagihan $t) => $t->sisa()), 0, ',', '.');

        return "Raport tersedia setelah tagihan berikut dilunasi: {$daftar} (total Rp{$total}).";
    }

    /** @return Collection<int, Tagihan> */
    public function tunggakanPenahan(Santri $santri, CarbonInterface $saatIni): Collection
    {
        return $santri->tunggakan($saatIni)
            ->whereHas('jenisTagihan', fn ($q) => $q->where('wajib_lunas_untuk_raport', true))
            ->orderBy('jatuh_tempo')
            ->get();
    }
}
