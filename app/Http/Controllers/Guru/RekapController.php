<?php

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Models\GuruMengajar;
use App\Models\Nilai;
use App\Services\NilaiService;
use App\Support\KonteksGuru;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menu "Rekap nilai". Dua tampilan:
 *  - Per mapel: rata-rata harian, UTS, UAS, nilai akhir, predikat, peringkat untuk satu kelas+mapel.
 *  - Per kelas: nilai akhir semua mapel (yang boleh dilihat) dalam satu tabel.
 * Guru melihat kelas+mapel yang diajarnya; pemegang nilai.lihat_semua melihat semuanya.
 */
class RekapController extends Controller
{
    public function index(Request $request, NilaiService $svc)
    {
        $k = KonteksGuru::dari($request, semua: true);
        $mode = $request->query('mode') === 'kelas' ? 'kelas' : 'mapel';
        $data = ['k' => $k, 'mode' => $mode, 'bobot' => config('khandaq.nilai.bobot')];

        if ($k->pilih && $mode === 'mapel') {
            $data += $svc->rekap($k->pilih->kelas_id, $k->pilih->mapel, $k->semester);
        } elseif ($k->pilih) {
            $data += $this->perKelas($k, $svc);
        }

        return view('guru.rekap', $data);
    }

    public function unduh(Request $request, NilaiService $svc): StreamedResponse
    {
        $k = KonteksGuru::dari($request, semua: true);
        abort_unless($k->pilih, 404);
        $r = $svc->rekap($k->pilih->kelas_id, $k->pilih->mapel, $k->semester);
        $nama = 'rekap-nilai_'.\Illuminate\Support\Str::slug($k->pilih->kelas->nama.' '.$k->pilih->mapel->nama.' '.$k->semester->nama).'.csv';

        return response()->streamDownload(function () use ($r, $k) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // agar Excel membaca UTF-8
            fputcsv($out, ['Kelas', $k->pilih->kelas->nama, 'Mapel', $k->pilih->mapel->nama, 'Semester', $k->semester->label, 'KKM', $k->pilih->mapel->kkm], ';');
            fputcsv($out, ['No', 'NIS', 'Nama', 'Harian 1', 'Harian 2', 'Harian 3', 'Tugas', 'Rata harian', 'UTS', 'UAS', 'Nilai akhir', 'Predikat', 'Tuntas', 'Peringkat'], ';');
            foreach ($r['baris'] as $i => $b) {
                $n = $b['nilai'];
                fputcsv($out, [$i + 1, $b['santri']->nis, $b['santri']->nama, $n->harian_1, $n->harian_2, $n->harian_3, $n->tugas,
                    self::koma($b['harian']), $b['uts'], $b['uas'], self::koma($b['akhir']), $b['predikat'],
                    $b['tuntas'] === null ? '' : ($b['tuntas'] ? 'Ya' : 'Belum'), $b['peringkat']], ';');
            }
            fclose($out);
        }, $nama, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function koma(?float $v): string
    {
        return $v === null ? '' : str_replace('.', ',', (string) $v);
    }

    /** Nilai akhir semua mapel di kelas terpilih (hanya mapel yang ada di daftar penugasan yang boleh dilihat). */
    private function perKelas(KonteksGuru $k, NilaiService $svc): array
    {
        $mapel = $k->penugasan->where('kelas_id', $k->pilih->kelas_id)->map(fn (GuruMengajar $g) => $g->mapel)->unique('id')->values();
        $santri = KonteksGuru::santri($k->pilih->kelas_id, $k->ta);
        $nilai = Nilai::where('semester_id', $k->semester->id)->whereIn('mapel_id', $mapel->pluck('id'))
            ->whereIn('santri_id', $santri->pluck('id'))->get()->groupBy('santri_id');

        $baris = $santri->map(function ($s) use ($nilai, $mapel) {
            $per = [];
            foreach ($mapel as $m) {
                $per[$m->id] = $nilai->get($s->id)?->firstWhere('mapel_id', $m->id)?->nilaiAkhir();
            }
            $isi = array_filter($per, fn ($v) => $v !== null);

            return ['santri' => $s, 'per' => $per, 'rata' => $isi ? round(array_sum($isi) / count($isi), 1) : null,
                'bawah' => count(array_filter($mapel->all(), fn ($m) => $per[$m->id] !== null && $per[$m->id] < $m->kkm))];
        })->sortByDesc(fn ($b) => $b['rata'] ?? -1)->values();

        return ['mapelKelas' => $mapel, 'barisKelas' => $baris];
    }
}
