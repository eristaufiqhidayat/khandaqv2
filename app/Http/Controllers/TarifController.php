<?php

namespace App\Http\Controllers;

use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Tagihan;
use App\Models\Tarif;
use App\Models\TahunAjaran;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menu "Tarif" (izin tarif.kelola): besaran SPP, DSB, Daftar Ulang, laundry, dst. per tahun ajaran,
 * boleh per kelas, boleh berubah di tengah tahun (berlaku_mulai). Tagihan yang sudah terbit tidak berubah.
 * Setiap perubahan tercatat di audit log.
 */
class TarifController extends Controller
{
    public function index(Request $request)
    {
        $daftarTa = TahunAjaran::orderByDesc('tahun_mulai')->get();
        $ta = $daftarTa->firstWhere('id', $request->integer('ta')) ?? TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());
        $tarif = Tarif::where('tahun_ajaran_id', $ta->id)->with(['kelas'])->orderBy('berlaku_mulai')->get()->groupBy('jenis_tagihan_id');
        $sebelumnya = TahunAjaran::where('tahun_mulai', $ta->tahun_mulai - 1)->first();

        return view('tarif.index', [
            'ta' => $ta, 'daftarTa' => $daftarTa, 'tarif' => $tarif,
            'jenis' => JenisTagihan::where('aktif', true)->orderBy('urutan_alokasi')->get(),
            'daftarKelas' => Kelas::where('aktif', true)->orderBy('nama')->get(),
            'sebelumnya' => $sebelumnya && Tarif::where('tahun_ajaran_id', $sebelumnya->id)->exists() ? $sebelumnya : null,
            'tahunDepan' => $ta->tahun_mulai >= CarbonImmutable::now()->year ? null : TahunAjaran::where('tahun_mulai', $ta->tahun_mulai + 1)->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'tahun_ajaran_id' => 'required|exists:tahun_ajaran,id', 'jenis_tagihan_id' => 'required|exists:jenis_tagihan,id',
            'kelas_id' => 'nullable|exists:kelas,id', 'berlaku_mulai' => 'required|date', 'nominal' => 'required|integer|min:0',
            'keterangan' => 'nullable|string|max:200',
        ]);
        $ta = TahunAjaran::findOrFail($d['tahun_ajaran_id']);
        if ($d['berlaku_mulai'] < $ta->mulai->toDateString() || $d['berlaku_mulai'] > $ta->selesai->toDateString()) {
            return back()->withInput()->withErrors(['tarif' => "Tanggal berlaku harus di dalam tahun ajaran {$ta->nama}."]);
        }
        Tarif::updateOrCreate(
            ['jenis_tagihan_id' => $d['jenis_tagihan_id'], 'tahun_ajaran_id' => $ta->id, 'kelas_id' => $d['kelas_id'] ?? null, 'berlaku_mulai' => $d['berlaku_mulai']],
            ['nominal' => $d['nominal'], 'keterangan' => $d['keterangan'] ?? null, 'dibuat_oleh' => $request->user()->id],
        );
        $jenis = JenisTagihan::find($d['jenis_tagihan_id']);

        return redirect()->route('tarif.index', ['ta' => $ta->id])->with('status', "Tarif {$jenis->nama} Rp".number_format($d['nominal'], 0, ',', '.')." disimpan. Tagihan yang sudah terbit tidak berubah.");
    }

    public function destroy(Tarif $tarif): RedirectResponse
    {
        if (Tagihan::where('tarif_id', $tarif->id)->exists()) {
            return back()->withErrors(['tarif' => 'Tarif ini sudah dipakai tagihan. Buat tarif baru dengan tanggal berlaku mulai yang lebih baru.']);
        }
        $ta = $tarif->tahun_ajaran_id;
        $tarif->delete();

        return redirect()->route('tarif.index', ['ta' => $ta])->with('status', 'Tarif dihapus.');
    }

    /** Siapkan tarif tahun ajaran baru dari tahun sebelumnya (nominal sama, bisa diubah setelahnya). */
    public function salin(Request $request, TahunAjaran $ta): RedirectResponse
    {
        $asal = TahunAjaran::where('tahun_mulai', $ta->tahun_mulai - 1)->firstOrFail();
        $n = DB::transaction(function () use ($asal, $ta, $request) {
            $n = 0;
            // Tarif terakhir per jenis+kelas di tahun sebelumnya, berlaku sejak awal tahun ajaran baru.
            foreach (Tarif::where('tahun_ajaran_id', $asal->id)->orderBy('berlaku_mulai')->get()->groupBy(fn ($t) => $t->jenis_tagihan_id.'|'.$t->kelas_id) as $grup) {
                $t = $grup->last();
                $baru = Tarif::firstOrCreate(
                    ['jenis_tagihan_id' => $t->jenis_tagihan_id, 'tahun_ajaran_id' => $ta->id, 'kelas_id' => $t->kelas_id, 'berlaku_mulai' => $ta->mulai->toDateString()],
                    ['nominal' => $t->nominal, 'keterangan' => 'Disalin dari '.$asal->nama, 'dibuat_oleh' => $request->user()->id],
                );
                $n += (int) $baru->wasRecentlyCreated;
            }

            return $n;
        });

        return redirect()->route('tarif.index', ['ta' => $ta->id])->with('status', "{$n} tarif disalin dari {$asal->nama}. Periksa dan ubah bila ada kenaikan.");
    }
}
