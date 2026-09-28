<?php

namespace App\Services;

use App\Enums\Izin;
use App\Enums\KeputusanTunggakan;
use App\Enums\StatusSantri;
use App\Exceptions\AturanDilanggar;
use App\Models\Kelas;
use App\Models\Peringatan;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\Otorisasi;
use Illuminate\Support\Facades\DB;

/**
 * Data master santri, dikelola Admin Office (izin santri.kelola):
 * pendaftaran santri, kode unik transfer, penempatan & kenaikan kelas, dan perubahan status.
 */
class DataSantriService
{
    public function tambah(array $data, Kelas $kelas, TahunAjaran $ta, User $petugas): Santri
    {
        Otorisasi::pastikan($petugas, Izin::SantriKelola);

        return DB::transaction(function () use ($data, $kelas, $ta) {
            $santri = Santri::create(array_merge($data, [
                'status' => StatusSantri::Aktif,
                'kode_unik' => $data['kode_unik'] ?? $this->kodeUnikBaru(),
            ]));
            $santri->riwayatKelas()->create(['kelas_id' => $kelas->id, 'tahun_ajaran_id' => $ta->id]);

            return $santri;
        });
    }

    /** Kode 3 digit (101-999) yang belum dipakai santri mana pun. */
    public function kodeUnikBaru(): string
    {
        $terpakai = Santri::withTrashed()->whereNotNull('kode_unik')->pluck('kode_unik')->flip();
        for ($k = 101; $k <= 999; $k++) {
            $kode = (string) $k;
            if (! isset($terpakai[$kode]) && $k % 100 !== 0) {
                return $kode;
            }
        }
        throw new AturanDilanggar('Kode unik habis; bebaskan kode alumni lebih dulu.');
    }

    /**
     * Kenaikan kelas massal di awal tahun ajaran.
     *
     * @param  array<int,int|null>  $peta  kelas_id lama => kelas_id baru (null = lulus)
     * @return array{naik:int, lulus:int}
     */
    public function naikkanKelas(TahunAjaran $dari, TahunAjaran $ke, array $peta, User $petugas): array
    {
        Otorisasi::pastikan($petugas, Izin::SantriKelola);

        return DB::transaction(function () use ($dari, $ke, $peta) {
            $hasil = ['naik' => 0, 'lulus' => 0];
            $riwayat = \App\Models\SantriKelas::where('tahun_ajaran_id', $dari->id)
                ->whereHas('santri', fn ($q) => $q->where('status', StatusSantri::Aktif->value))
                ->with('santri')->get();
            foreach ($riwayat as $r) {
                if (! array_key_exists($r->kelas_id, $peta)) {
                    continue; // kelas tidak dipetakan: tinggal kelas, diatur manual
                }
                $baru = $peta[$r->kelas_id];
                if ($baru === null) {
                    $this->ubahStatusInternal($r->santri, StatusSantri::Alumni);
                    $hasil['lulus']++;
                } else {
                    $r->santri->riwayatKelas()->updateOrCreate(['tahun_ajaran_id' => $ke->id], ['kelas_id' => $baru]);
                    $hasil['naik']++;
                }
            }

            return $hasil;
        });
    }

    /** Calon santri (hasil pendaftaran) mulai aktif: tagihan bulanan terbit untuknya sejak bulan ini. */
    public function aktifkan(Santri $santri, \Carbon\CarbonInterface $tanggalMasuk, User $petugas): Santri
    {
        Otorisasi::pastikan($petugas, Izin::SantriKelola);
        if ($santri->status !== StatusSantri::Calon) {
            throw new AturanDilanggar('Hanya calon santri yang bisa diaktifkan.');
        }
        $santri->update(['status' => StatusSantri::Aktif, 'tanggal_masuk' => $tanggalMasuk]);

        return $santri;
    }

    /**
     * Santri keluar. Bila alasannya pemulangan karena tunggakan, harus sudah ada keputusan Keuangan.
     */
    public function keluarkan(Santri $santri, string $alasan, User $petugas, bool $karenaTunggakan = false): Santri
    {
        Otorisasi::pastikan($petugas, Izin::SantriKelola);
        if ($karenaTunggakan) {
            $ada = Peringatan::where('santri_id', $santri->id)
                ->where('keputusan', KeputusanTunggakan::Pemulangan->value)->exists();
            if (! $ada) {
                throw new AturanDilanggar('Pemulangan karena tunggakan memerlukan keputusan Keuangan lebih dulu.');
            }
        }
        $santri->catatan = trim(($santri->catatan ?? '')."\nKeluar: ".$alasan);
        $this->ubahStatusInternal($santri, StatusSantri::Keluar);

        return $santri;
    }

    /** Alumni/keluar: kode unik dibebaskan agar bisa dipakai santri baru; riwayat tetap lewat santri_id. */
    private function ubahStatusInternal(Santri $santri, StatusSantri $status): void
    {
        $santri->status = $status;
        if (in_array($status, [StatusSantri::Alumni, StatusSantri::Keluar], true) && $santri->kode_unik) {
            $santri->catatan = trim(($santri->catatan ?? '')."\nKode unik lama: ".$santri->kode_unik);
            $santri->kode_unik = null;
        }
        $santri->save();
    }
}
