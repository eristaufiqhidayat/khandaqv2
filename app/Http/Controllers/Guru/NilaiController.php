<?php

namespace App\Http\Controllers\Guru;

use App\Exceptions\AturanDilanggar;
use App\Http\Controllers\Controller;
use App\Models\Mapel;
use App\Models\Nilai;
use App\Models\Semester;
use App\Services\NilaiService;
use App\Support\KonteksGuru;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Menu "Input nilai siswa": nilai Harian (3 harian + tugas), UTS, UAS per kelas & mapel yang diajar. */
class NilaiController extends Controller
{
    public function index(Request $request)
    {
        $k = KonteksGuru::dari($request);
        $tab = array_key_exists($t = (string) $request->query('tab'), NilaiService::TAB) ? $t : 'harian';
        $santri = collect();
        $nilai = collect();
        if ($k->pilih) {
            $santri = KonteksGuru::santri($k->pilih->kelas_id, $k->ta);
            $nilai = Nilai::where('mapel_id', $k->pilih->mapel_id)->where('semester_id', $k->semester->id)
                ->whereIn('santri_id', $santri->pluck('id'))->get()->keyBy('santri_id');
        }

        return view('guru.nilai', ['k' => $k, 'tab' => $tab, 'tabs' => NilaiService::TAB, 'santri' => $santri, 'nilai' => $nilai]);
    }

    public function simpan(Request $request, NilaiService $svc): RedirectResponse
    {
        $d = $request->validate([
            'semester' => 'required|integer|exists:semester,id',
            'kelas' => 'required|integer', 'mapel' => 'required|integer|exists:mapel,id',
            'tab' => ['required', Rule::in(array_keys(NilaiService::TAB))],
            'nilai' => 'array', 'nilai.*' => 'array',
            'nilai.*.*' => 'nullable',
            'nilai.*.harian_1' => 'nullable|integer|between:0,100', 'nilai.*.harian_2' => 'nullable|integer|between:0,100',
            'nilai.*.harian_3' => 'nullable|integer|between:0,100', 'nilai.*.tugas' => 'nullable|integer|between:0,100',
            'nilai.*.uts' => 'nullable|integer|between:0,100', 'nilai.*.uas' => 'nullable|integer|between:0,100',
            'nilai.*.catatan' => 'nullable|string|max:255',
        ], ['nilai.*.*.integer' => 'Nilai harus bilangan bulat 0–100.', 'nilai.*.*.between' => 'Nilai harus 0–100.']);

        $semester = Semester::with('tahunAjaran')->findOrFail($d['semester']);
        $mapel = Mapel::findOrFail($d['mapel']);
        $kembali = route('guru.nilai.index', ['ta' => $semester->tahun_ajaran_id, 'semester' => $semester->id, 'kelas' => $d['kelas'], 'mapel' => $mapel->id, 'tab' => $d['tab']]);
        try {
            $n = $svc->simpan($request->user(), (int) $d['kelas'], $mapel, $semester, $d['tab'], $d['nilai'] ?? []);
        } catch (AturanDilanggar $e) {
            return redirect($kembali)->withInput()->withErrors(['nilai' => $e->getMessage()]);
        }

        return redirect($kembali)->with('status', NilaiService::TAB[$d['tab']]['label'].' '.$mapel->nama.' disimpan'.($n ? " ({$n} santri diperbarui)." : ' (tidak ada perubahan).'));
    }
}
