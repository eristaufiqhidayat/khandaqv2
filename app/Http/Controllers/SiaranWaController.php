<?php

namespace App\Http\Controllers;

use App\Models\Kelas;
use App\Models\WaSiaran;
use App\Services\Whatsapp\SiaranWaService;
use Illuminate\Http\Request;

/**
 * Menu "Siaran WhatsApp" (izin wa.siaran; route middleware can:wa.siaran).
 * GET /siaran · POST /siaran/pratinjau · POST /siaran · POST /siaran/{siaran}/kirim · POST /siaran/{siaran}/batal · GET /siaran/{siaran}
 */
class SiaranWaController extends Controller
{
    public function __construct(private SiaranWaService $svc) {}

    public function index()
    {
        return view('siaran.index', [
            'siaran' => WaSiaran::latest()->paginate(20),
            'kelas' => Kelas::orderBy('nama')->get(),
        ]);
    }

    public function pratinjau(Request $r)
    {
        $hasil = $this->svc->tujuan($this->sasaran($r));

        return response()->json(['jumlah' => $hasil['tujuan']->count(), 'tanpa_nomor' => $hasil['tanpa_nomor'],
            'contoh' => $hasil['tujuan']->take(5)->map(fn ($t) => $t['nama_wali'].' ('.$t['nama_santri'].')')]);
    }

    public function store(Request $r)
    {
        $data = $r->validate(['judul' => 'required|max:100', 'isi' => 'required|max:4000', 'template' => 'nullable|max:60']);
        $siaran = $this->svc->buat($r->user(), $data['judul'], $data['isi'], $this->sasaran($r), $data['template'] ?? null);

        return redirect()->route('siaran.show', $siaran);
    }

    public function kirim(Request $r, WaSiaran $siaran)
    {
        $this->svc->kirim($siaran, $r->user());

        return redirect()->route('siaran.show', $siaran);
    }

    public function batal(Request $r, WaSiaran $siaran)
    {
        $this->svc->batalkan($siaran, $r->user());

        return back();
    }

    public function show(WaSiaran $siaran)
    {
        return view('siaran.show', ['siaran' => $siaran, 'pesan' => $siaran->pesan()->with('user')->orderBy('id')->paginate(50)]);
    }

    private function sasaran(Request $r): array
    {
        $d = $r->validate(['jenis' => 'required|in:semua_wali,kelas,tunggakan', 'kelas_ids' => 'array', 'kelas_ids.*' => 'integer', 'min_bulan' => 'nullable|integer|min:1|max:12']);

        return array_filter(['jenis' => $d['jenis'], 'kelas_ids' => $d['kelas_ids'] ?? null, 'min_bulan' => $d['min_bulan'] ?? null]);
    }
}
