<?php

namespace App\Services;

use App\Enums\Izin;
use App\Enums\JenisKeringanan;
use App\Enums\StatusKeringanan;
use App\Exceptions\AturanDilanggar;
use App\Models\JenisTagihan;
use App\Models\Keringanan;
use App\Models\Santri;
use App\Models\Semester;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Beasiswa (Admin menginput), diskon DSB/DU, dan dispensasi raport.
 * Semuanya baru berlaku setelah DISETUJUI oleh Keuangan (izin keringanan.setujui),
 * dan penyetuju tidak boleh sama dengan pengaju.
 */
class KeringananService
{
    public function ajukanBeasiswa(Santri $santri, TahunAjaran $ta, ?float $persen, ?int $nominal, string $alasan, User $pengaju): Keringanan
    {
        $this->pastikanPotongan($persen, $nominal);

        return $this->ajukan(JenisKeringanan::Beasiswa, $santri, $ta, $pengaju, Izin::BeasiswaAjukan, [
            'jenis_tagihan_id' => JenisTagihan::kode(JenisTagihan::SPP)->id,
            'persen' => $persen, 'nominal' => $nominal, 'alasan' => $alasan,
        ]);
    }

    public function ajukanDiskon(Santri $santri, TahunAjaran $ta, JenisTagihan $jenis, ?float $persen, ?int $nominal, string $alasan, User $pengaju, ?string $lampiran = null): Keringanan
    {
        if (! in_array($jenis->kode, [JenisTagihan::DSB, JenisTagihan::DU], true)) {
            throw new AturanDilanggar('Diskon hanya untuk DSB atau Daftar Ulang.');
        }
        $this->pastikanPotongan($persen, $nominal);

        return $this->ajukan(JenisKeringanan::Diskon, $santri, $ta, $pengaju, Izin::KeringananAjukan, [
            'jenis_tagihan_id' => $jenis->id, 'persen' => $persen, 'nominal' => $nominal,
            'alasan' => $alasan, 'lampiran_path' => $lampiran,
        ]);
    }

    public function ajukanDispensasiRaport(Santri $santri, Semester $semester, CarbonInterface $janjiBayar, string $alasan, User $pengaju): Keringanan
    {
        return $this->ajukan(JenisKeringanan::DispensasiRaport, $santri, $semester->tahunAjaran, $pengaju, Izin::KeringananAjukan, [
            'semester_id' => $semester->id, 'berlaku_sampai' => $janjiBayar, 'alasan' => $alasan,
        ]);
    }

    public function setujui(Keringanan $k, User $pemutus, ?string $catatan = null): Keringanan
    {
        $this->pastikanBolehMemutus($k, $pemutus);

        return DB::transaction(function () use ($k, $pemutus, $catatan) {
            // Beasiswa/diskon baru menggantikan yang lama untuk jenis & tahun ajaran yang sama.
            if ($k->jenis !== JenisKeringanan::DispensasiRaport) {
                Keringanan::disetujui()
                    ->where('santri_id', $k->santri_id)->where('tahun_ajaran_id', $k->tahun_ajaran_id)
                    ->where('jenis_tagihan_id', $k->jenis_tagihan_id)->where('jenis', $k->jenis->value)
                    ->update(['status' => StatusKeringanan::Dibatalkan->value]);
            }
            $k->update([
                'status' => StatusKeringanan::Disetujui, 'diputuskan_oleh' => $pemutus->id,
                'diputuskan_pada' => CarbonImmutable::now(), 'catatan_keputusan' => $catatan,
            ]);
            if ($k->jenis !== JenisKeringanan::DispensasiRaport) {
                $this->terapkanKeTagihanTerbuka($k);
            }

            return $k;
        });
    }

    public function tolak(Keringanan $k, User $pemutus, string $catatan): Keringanan
    {
        $this->pastikanBolehMemutus($k, $pemutus);
        $k->update([
            'status' => StatusKeringanan::Ditolak, 'diputuskan_oleh' => $pemutus->id,
            'diputuskan_pada' => CarbonImmutable::now(), 'catatan_keputusan' => $catatan,
        ]);

        return $k;
    }

    /** Dispensasi raport yang disetujui untuk santri & semester. */
    public function dispensasiRaport(Santri $santri, Semester $semester): ?Keringanan
    {
        return Keringanan::disetujui()
            ->where('jenis', JenisKeringanan::DispensasiRaport->value)
            ->where('santri_id', $santri->id)->where('semester_id', $semester->id)
            ->first();
    }

    private function ajukan(JenisKeringanan $jenis, Santri $santri, TahunAjaran $ta, User $pengaju, Izin $izin, array $data): Keringanan
    {
        Otorisasi::pastikan($pengaju, $izin);

        return Keringanan::create(array_merge($data, [
            'jenis' => $jenis, 'santri_id' => $santri->id, 'tahun_ajaran_id' => $ta->id,
            'status' => StatusKeringanan::Diajukan, 'diajukan_oleh' => $pengaju->id,
        ]));
    }

    private function pastikanBolehMemutus(Keringanan $k, User $pemutus): void
    {
        Otorisasi::pastikan($pemutus, Izin::KeringananSetujui);
        if ($k->status !== StatusKeringanan::Diajukan) {
            throw new AturanDilanggar('Pengajuan ini sudah diputuskan.');
        }
        if ($k->diajukan_oleh === $pemutus->id) {
            throw new AturanDilanggar('Pengaju tidak boleh menyetujui pengajuannya sendiri.');
        }
    }

    private function pastikanPotongan(?float $persen, ?int $nominal): void
    {
        if (($persen === null) === ($nominal === null)) {
            throw new AturanDilanggar('Isi salah satu: persen atau nominal potongan.');
        }
        if ($persen !== null && ($persen <= 0 || $persen > 100)) {
            throw new AturanDilanggar('Persen harus antara 0 dan 100.');
        }
    }

    /** Potongan dihitung ulang pada tagihan yang belum lunas; tagihan lunas tidak diubah. */
    private function terapkanKeTagihanTerbuka(Keringanan $k): void
    {
        Tagihan::terbuka()
            ->where('santri_id', $k->santri_id)->where('tahun_ajaran_id', $k->tahun_ajaran_id)
            ->where('jenis_tagihan_id', $k->jenis_tagihan_id)
            ->each(function (Tagihan $t) use ($k) {
                $t->potongan = $k->potonganUntuk($t->nominal);
                $t->keringanan_id = $k->id;
                $t->save();
                $t->segarkanStatus();
            });
    }
}
