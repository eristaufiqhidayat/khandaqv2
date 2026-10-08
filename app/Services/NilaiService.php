<?php

namespace App\Services;

use App\Exceptions\AturanDilanggar;
use App\Models\Mapel;
use App\Models\Nilai;
use App\Models\Semester;
use App\Models\User;
use App\Support\KonteksGuru;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Simpan nilai per tab (Harian / UTS / UAS) dan hitung rekap satu kelas+mapel. */
class NilaiService
{
    /** Kolom yang diisi per tab layar Input nilai. */
    public const TAB = [
        'harian' => ['kolom' => Nilai::HARIAN, 'catatan' => 'catatan_harian', 'label' => 'Nilai Harian'],
        'uts' => ['kolom' => ['uts'], 'catatan' => 'catatan_uts', 'label' => 'UTS'],
        'uas' => ['kolom' => ['uas'], 'catatan' => 'catatan_uas', 'label' => 'UAS'],
    ];

    /**
     * @param  array<int|string, array<string, mixed>>  $baris  santri_id => [kolom => nilai|null, 'catatan' => ?string]
     * @return int jumlah baris santri yang tersimpan
     */
    public function simpan(User $guru, int $kelasId, Mapel $mapel, Semester $semester, string $tab, array $baris): int
    {
        $ta = $semester->tahunAjaran;
        if (! KonteksGuru::mengajar($guru, $kelasId, $mapel->id, $ta)) {
            throw new AturanDilanggar('Anda tidak ditugaskan mengajar mapel ini di kelas ini.');
        }
        $cfg = self::TAB[$tab] ?? throw new AturanDilanggar('Jenis nilai tidak dikenal.');
        $roster = KonteksGuru::santri($kelasId, $ta)->keyBy('id');

        return DB::transaction(function () use ($baris, $roster, $cfg, $mapel, $semester, $kelasId, $guru) {
            $n = 0;
            foreach ($baris as $santriId => $isi) {
                if (! $roster->has((int) $santriId)) {
                    continue; // bukan santri kelas ini: abaikan
                }
                $data = [];
                foreach ($cfg['kolom'] as $k) {
                    $data[$k] = self::angka($isi[$k] ?? null);
                }
                $data[$cfg['catatan']] = filled($isi['catatan'] ?? null) ? mb_substr(trim((string) $isi['catatan']), 0, 255) : null;

                $nilai = Nilai::firstOrNew(['santri_id' => (int) $santriId, 'mapel_id' => $mapel->id, 'semester_id' => $semester->id]);
                if (! $nilai->exists && collect($data)->filter(fn ($v) => $v !== null)->isEmpty()) {
                    continue; // tidak membuat baris kosong
                }
                $nilai->fill($data + ['kelas_id' => $kelasId]);
                if ($nilai->isDirty()) {
                    $nilai->diubah_oleh = $guru->id;
                    $nilai->save();
                    $n++;
                }
            }

            return $n;
        });
    }

    private static function angka(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v) || $v < 0 || $v > 100) {
            throw new AturanDilanggar('Nilai harus angka 0 sampai 100.');
        }

        return (int) round((float) $v);
    }

    /**
     * Rekap satu kelas+mapel: semua santri aktif kelas itu (yang belum dinilai ikut tampil, kosong).
     *
     * @return array{baris: Collection<int, array<string, mixed>>, ringkas: array<string, mixed>}
     */
    public function rekap(int $kelasId, Mapel $mapel, Semester $semester): array
    {
        $santri = KonteksGuru::santri($kelasId, $semester->tahunAjaran);
        $nilai = Nilai::where('mapel_id', $mapel->id)->where('semester_id', $semester->id)
            ->whereIn('santri_id', $santri->pluck('id'))->get()->keyBy('santri_id');

        $baris = $santri->map(function ($s) use ($nilai, $mapel) {
            $n = $nilai->get($s->id) ?? new Nilai;
            $akhir = $n->nilaiAkhir();

            return [
                'santri' => $s, 'nilai' => $n, 'harian' => $n->rataHarian(), 'uts' => $n->uts, 'uas' => $n->uas,
                'akhir' => $akhir, 'predikat' => Nilai::predikat($akhir, $mapel->kkm),
                'tuntas' => $akhir === null ? null : $akhir >= $mapel->kkm,
            ];
        })->values();

        // Peringkat dari nilai akhir (sama nilai = sama peringkat).
        $urut = $baris->pluck('akhir')->filter(fn ($v) => $v !== null)->sortDesc()->values();
        $baris = $baris->map(fn ($b) => $b + ['peringkat' => $b['akhir'] === null ? null : $urut->search($b['akhir']) + 1]);

        $akhir = $baris->pluck('akhir')->filter(fn ($v) => $v !== null);

        return ['baris' => $baris, 'ringkas' => [
            'jumlah' => $baris->count(),
            'dinilai' => $akhir->count(),
            'rata' => $akhir->isEmpty() ? null : round($akhir->avg(), 1),
            'tertinggi' => $akhir->max(),
            'terendah' => $akhir->min(),
            'bawah_kkm' => $akhir->filter(fn ($v) => $v < $mapel->kkm)->count(),
            'terisi' => [
                'harian' => $baris->filter(fn ($b) => $b['harian'] !== null)->count(),
                'uts' => $baris->filter(fn ($b) => $b['uts'] !== null)->count(),
                'uas' => $baris->filter(fn ($b) => $b['uas'] !== null)->count(),
            ],
        ]];
    }
}
