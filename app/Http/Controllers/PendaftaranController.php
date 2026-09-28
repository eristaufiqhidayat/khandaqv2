<?php

namespace App\Http\Controllers;

use App\Enums\StatusPendaftaran;
use App\Exceptions\AturanDilanggar;
use App\Models\Kelas;
use App\Models\Pendaftaran;
use App\Models\TahunAjaran;
use App\Services\PendaftaranService;
use App\Services\Whatsapp\KirimAksesWali;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Pendaftaran santri baru" (izin pendaftaran.proses):
 * baru -> formulir lunas -> diterima (santri calon + akun wali + tagihan DSB) atau ditolak.
 * Admin Office bisa mencatat pendaftaran yang datang langsung ke kantor.
 */
class PendaftaranController extends Controller
{
    public function __construct(private PendaftaranService $svc) {}

    public function index(Request $request)
    {
        $tab = in_array($request->query('status'), ['baru', 'formulir_lunas', 'diterima', 'ditolak'], true) ? $request->query('status') : 'proses';
        $daftar = Pendaftaran::with('santri')
            ->when($tab === 'proses', fn ($q) => $q->whereIn('status', [StatusPendaftaran::Baru->value, StatusPendaftaran::FormulirLunas->value]))
            ->when($tab !== 'proses', fn ($q) => $q->where('status', $tab))
            ->latest()->paginate(30)->withQueryString();
        $hitung = Pendaftaran::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return view('pendaftaran.index', [
            'daftar' => $daftar, 'tab' => $tab, 'hitung' => $hitung,
            'daftarKelas' => Kelas::orderBy('nama')->get(),
            'taTujuan' => $this->taTujuan(),
            'jatuhTempoDsb' => CarbonImmutable::now()->addDays(30)->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'nama_calon' => 'required|string|max:100', 'jenis_kelamin' => ['required', Rule::in(['laki-laki', 'perempuan'])],
            'tempat_lahir' => 'nullable|string|max:50', 'tanggal_lahir' => 'nullable|date', 'asal_sekolah' => 'nullable|string|max:100',
            'tingkat_tujuan' => 'required|integer|min:1|max:6',
            'nama_wali' => 'required|string|max:100', 'hubungan_wali' => ['required', Rule::in(['ayah', 'ibu', 'wali'])],
            'telepon_wali' => 'required|string|max:20', 'email_wali' => 'nullable|email|max:100', 'pekerjaan_wali' => 'nullable|string|max:100',
            'alamat' => 'required|string|max:500',
        ]);
        try {
            $p = $this->svc->daftar($d, $this->taTujuan());
        } catch (AturanDilanggar $e) {
            return back()->withInput()->withErrors(['pendaftaran' => $e->getMessage()]);
        }

        return redirect()->route('pendaftaran.index')->with('status', "Pendaftaran {$p->nomor} ({$p->nama_calon}) dicatat.");
    }

    public function lunas(Request $request, Pendaftaran $pendaftaran): RedirectResponse
    {
        $d = $request->validate(['biaya' => 'required|integer|min:0', 'bukti' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096']);
        $bukti = $request->file('bukti')?->store('bukti-formulir');

        return $this->jalankan(fn () => $this->svc->formulirLunas($pendaftaran, (int) $d['biaya'], $request->user(), $bukti),
            "Formulir {$pendaftaran->nomor} lunas.");
    }

    public function terima(Request $request, Pendaftaran $pendaftaran, KirimAksesWali $akses): RedirectResponse
    {
        $d = $request->validate([
            'kelas_id' => 'required|exists:kelas,id', 'nis' => 'required|string|max:20|unique:santri,nis',
            'jatuh_tempo_dsb' => 'required|date',
        ]);
        try {
            $hasil = $this->svc->terima($pendaftaran, Kelas::findOrFail($d['kelas_id']), $d['nis'], CarbonImmutable::parse($d['jatuh_tempo_dsb']), $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['pendaftaran' => $e->getMessage()]);
        }
        $pesan = "{$hasil['santri']->nama} diterima sebagai calon santri (kode transfer {$hasil['santri']->kode_unik}). ";
        $pesan .= $hasil['dsb']
            ? 'Tagihan DSB Rp'.number_format($hasil['dsb']->nominal, 0, ',', '.').' dibuat. '
            : "PERHATIAN: tarif DSB {$pendaftaran->tahunAjaran->nama} belum diatur, jadi tagihan DSB belum dibuat. Atur tarifnya di menu Tarif. ";
        $pesan .= $hasil['akun_baru']
            ? $akses->kirim($hasil['wali'], $hasil['password_awal'])['pesan']
            : "Wali sudah punya akun (kakak/adik), santri ditautkan ke akun {$hasil['wali']->name}.";

        return redirect()->route('santri.show', $hasil['santri'])->with('status', $pesan);
    }

    public function tolak(Request $request, Pendaftaran $pendaftaran): RedirectResponse
    {
        $d = $request->validate(['alasan' => 'required|string|max:500']);

        return $this->jalankan(fn () => $this->svc->tolak($pendaftaran, $d['alasan'], $request->user()), "Pendaftaran {$pendaftaran->nomor} ditolak.");
    }

    /** PSB selalu untuk tahun ajaran yang dimulai Juli berikutnya (santri pindahan tengah tahun lewat Data santri). */
    private function taTujuan(): TahunAjaran
    {
        $sekarang = CarbonImmutable::now();

        return TahunAjaran::untukTanggal(CarbonImmutable::create($sekarang->month >= 7 ? $sekarang->year + 1 : $sekarang->year, 7, 1));
    }

    private function jalankan(callable $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['pendaftaran' => $e->getMessage()]);
        }

        return redirect()->route('pendaftaran.index')->with('status', $pesan);
    }
}
