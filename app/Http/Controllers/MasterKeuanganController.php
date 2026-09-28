<?php

namespace App\Http\Controllers;

use App\Models\Akun;
use App\Models\Dana;
use App\Models\JenisTagihan;
use App\Models\Pengusul;
use App\Models\Rekening;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Rekening & akun biaya" (izin master_keuangan.kelola): rekening kas/bank, akun biaya, dan pengusul
 * untuk pencatatan pengeluaran. Dana dan jenis tagihan ditampilkan saja (diubah lewat seeder/pengembang,
 * karena aturan potong otomatis & raport bergantung padanya).
 */
class MasterKeuanganController extends Controller
{
    private const MODEL = ['rekening' => Rekening::class, 'akun' => Akun::class, 'pengusul' => Pengusul::class];

    public function index()
    {
        return view('masterkeu.index', [
            'rekening' => Rekening::orderBy('kode')->get(), 'akun' => Akun::orderBy('kode')->get(), 'pengusul' => Pengusul::orderBy('kode')->get(),
            'dana' => Dana::orderBy('kode')->get(), 'jenis' => JenisTagihan::with('dana')->orderBy('urutan_alokasi')->get(),
        ]);
    }

    public function store(Request $request, string $jenis): RedirectResponse
    {
        abort_unless(isset(self::MODEL[$jenis]), 404);
        $model = self::MODEL[$jenis];
        $aturan = ['kode' => ['required', 'string', 'max:20', Rule::unique((new $model)->getTable(), 'kode')], 'nama' => 'required|string|max:100'];
        if ($jenis === 'rekening') {
            $aturan += ['jenis' => ['required', Rule::in(['kas', 'bank'])], 'bank' => 'nullable|string|max:50', 'nomor' => 'nullable|string|max:50'];
        }
        $d = $request->validate($aturan);
        $model::create($d + ($jenis === 'rekening' ? ['aktif' => true] : []));

        return back()->with('status', ucfirst($jenis)." {$d['kode']} ditambahkan.");
    }

    public function update(Request $request, string $jenis, int $id): RedirectResponse
    {
        abort_unless(isset(self::MODEL[$jenis]), 404);
        $row = (self::MODEL[$jenis])::findOrFail($id);
        $d = $request->validate(['nama' => 'required|string|max:100', 'aktif' => 'nullable|boolean', 'nomor' => 'nullable|string|max:50']);
        $row->update(array_intersect_key($d + ['aktif' => $request->boolean('aktif')], array_flip($jenis === 'rekening' ? ['nama', 'aktif', 'nomor'] : ['nama'])));

        return back()->with('status', ucfirst($jenis)." {$row->kode} disimpan.");
    }
}
