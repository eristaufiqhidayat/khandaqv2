<?php

namespace App\Services;

use App\Enums\Frekuensi;
use App\Enums\JenisKeringanan;
use App\Enums\StatusTagihan;
use App\Models\JenisTagihan;
use App\Models\Keringanan;
use App\Models\Santri;
use App\Models\Semester;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Membuat tagihan. Idempoten: menjalankan ulang untuk periode yang sama tidak membuat duplikat.
 * Dijadwalkan: bulanan() tiap tanggal 1 (SPP, laundry, kesehatan).
 */
class TagihanGenerator
{
    /** Tagihan bulanan (frekuensi bulanan) untuk semua santri aktif, atau satu santri. */
    public function bulanan(CarbonInterface $bulan, ?Santri $hanya = null): int
    {
        $awal = CarbonImmutable::parse($bulan)->startOfMonth();
        $ta = TahunAjaran::untukTanggal($awal);
        $jenisList = JenisTagihan::where('frekuensi', Frekuensi::Bulanan->value)->where('aktif', true)->get();

        $dibuat = 0;
        foreach ($this->santriBerkelas($ta, $hanya) as [$santri, $kelas]) {
            foreach ($jenisList as $jenis) {
                if (! $jenis->berlakuUntuk($santri, $awal)) {
                    continue;
                }
                $tarif = $jenis->tarifUntuk($ta, $kelas, $awal);
                if (! $tarif) {
                    continue; // tarif belum diatur: lihat laporan "tarif kosong"
                }
                $dibuat += (int) $this->buat($santri, $jenis, $ta, [
                    'semester_id' => $ta->semesterUntuk($awal)->id,
                    'periode' => $awal->toDateString(),
                    'keterangan' => $jenis->nama.' '.$awal->locale('id')->translatedFormat('F Y'),
                    'nominal' => $tarif->nominal,
                    'tarif_id' => $tarif->id,
                    'jatuh_tempo' => $awal->endOfMonth()->toDateString(), // keputusan: jatuh tempo akhir bulan
                ]);
            }
        }

        return $dibuat;
    }

    /** Tagihan per semester (PTS/PAS) dengan jatuh tempo yang ditentukan (sebelum ujian). */
    public function perSemester(Semester $semester, JenisTagihan $jenis, CarbonInterface $jatuhTempo, ?Santri $hanya = null): int
    {
        $ta = $semester->tahunAjaran;
        $dibuat = 0;
        foreach ($this->santriBerkelas($ta, $hanya) as [$santri, $kelas]) {
            if (! $jenis->berlakuUntuk($santri, $jatuhTempo)) {
                continue;
            }
            $tarif = $jenis->tarifUntuk($ta, $kelas, $semester->mulai);
            if (! $tarif) {
                continue;
            }
            $dibuat += (int) $this->buat($santri, $jenis, $ta, [
                'semester_id' => $semester->id,
                'periode' => $semester->mulai->toDateString(),
                'keterangan' => $jenis->nama.' '.$semester->nama,
                'nominal' => $tarif->nominal,
                'tarif_id' => $tarif->id,
                'jatuh_tempo' => CarbonImmutable::parse($jatuhTempo)->toDateString(),
            ]);
        }

        return $dibuat;
    }

    /** Daftar Ulang: santri aktif yang masuk sebelum tahun ajaran ini (santri lama). */
    public function daftarUlang(TahunAjaran $ta, ?CarbonInterface $jatuhTempo = null): int
    {
        $jenis = JenisTagihan::kode(JenisTagihan::DU);
        $jatuhTempo ??= $ta->mulai->endOfMonth(); // 31 Juli
        $dibuat = 0;
        foreach ($this->santriBerkelas($ta) as [$santri, $kelas]) {
            if ($santri->tanggal_masuk && $santri->tanggal_masuk->greaterThanOrEqualTo($ta->mulai)) {
                continue; // santri baru membayar DSB, bukan DU
            }
            $tarif = $jenis->tarifUntuk($ta, $kelas, $ta->mulai);
            if (! $tarif || ! $jenis->berlakuUntuk($santri, $ta->mulai)) {
                continue;
            }
            $dibuat += (int) $this->buat($santri, $jenis, $ta, [
                'periode' => $ta->mulai->toDateString(),
                'keterangan' => $jenis->nama.' '.$ta->nama,
                'nominal' => $tarif->nominal,
                'tarif_id' => $tarif->id,
                'jatuh_tempo' => CarbonImmutable::parse($jatuhTempo)->toDateString(),
            ]);
        }

        return $dibuat;
    }

    /** DSB untuk satu santri baru; dibuat saat pendaftaran diterima. */
    public function dsb(Santri $santri, TahunAjaran $ta, CarbonInterface $jatuhTempo): ?Tagihan
    {
        $jenis = JenisTagihan::kode(JenisTagihan::DSB);
        $tarif = $jenis->tarifUntuk($ta, $santri->kelasPada($ta), $ta->mulai);
        if (! $tarif) {
            return null;
        }
        $this->buat($santri, $jenis, $ta, [
            'periode' => $ta->mulai->toDateString(),
            'keterangan' => $jenis->nama.' '.$ta->nama,
            'nominal' => $tarif->nominal,
            'tarif_id' => $tarif->id,
            'jatuh_tempo' => CarbonImmutable::parse($jatuhTempo)->toDateString(),
        ]);

        return Tagihan::where(['santri_id' => $santri->id, 'jenis_tagihan_id' => $jenis->id, 'tahun_ajaran_id' => $ta->id])->first();
    }

    /** Tagihan insidental (kegiatan, buku, rihlah) untuk sekelompok santri. */
    public function insidental(iterable $santriList, JenisTagihan $jenis, int $nominal, string $keterangan, CarbonInterface $jatuhTempo): int
    {
        $ta = TahunAjaran::untukTanggal($jatuhTempo);
        $n = 0;
        foreach ($santriList as $santri) {
            Tagihan::create([
                'santri_id' => $santri->id, 'jenis_tagihan_id' => $jenis->id, 'tahun_ajaran_id' => $ta->id,
                'periode' => null, 'keterangan' => $keterangan, 'nominal' => $nominal,
                'jatuh_tempo' => CarbonImmutable::parse($jatuhTempo)->toDateString(),
                'status' => StatusTagihan::Belum,
            ]);
            $n++;
        }

        return $n;
    }

    /** @return Collection<int, array{0: Santri, 1: ?\App\Models\Kelas}> */
    private function santriBerkelas(TahunAjaran $ta, ?Santri $hanya = null): Collection
    {
        return Santri::aktif()
            ->when($hanya, fn ($q) => $q->whereKey($hanya->id))
            ->with(['riwayatKelas' => fn ($q) => $q->where('tahun_ajaran_id', $ta->id)->with('kelas')])
            ->get()
            ->map(fn (Santri $s) => [$s, $s->riwayatKelas->first()?->kelas]);
    }

    /** @return bool true bila baris baru dibuat */
    private function buat(Santri $santri, JenisTagihan $jenis, TahunAjaran $ta, array $data): bool
    {
        $kunci = [
            'santri_id' => $santri->id,
            'jenis_tagihan_id' => $jenis->id,
            'tahun_ajaran_id' => $ta->id,
            'periode' => $data['periode'],
        ];
        if (Tagihan::where($kunci)->exists()) {
            return false;
        }

        $keringanan = $this->keringananUntuk($santri, $jenis, $ta);
        Tagihan::create($kunci + $data + [
            'potongan' => $keringanan?->potonganUntuk($data['nominal']) ?? 0,
            'keringanan_id' => $keringanan?->id,
            'status' => StatusTagihan::Belum,
        ]);

        return true;
    }

    private function keringananUntuk(Santri $santri, JenisTagihan $jenis, TahunAjaran $ta): ?Keringanan
    {
        return Keringanan::disetujui()
            ->where('santri_id', $santri->id)
            ->where('tahun_ajaran_id', $ta->id)
            ->where('jenis_tagihan_id', $jenis->id)
            ->whereIn('jenis', [JenisKeringanan::Beasiswa->value, JenisKeringanan::Diskon->value])
            ->latest('diputuskan_pada')
            ->first();
    }
}
