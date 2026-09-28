<?php

namespace App\Http\Controllers;

use App\Enums\Izin;
use App\Enums\StatusSantri;
use App\Exceptions\AturanDilanggar;
use App\Models\Kelas;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\AkunService;
use App\Services\DataSantriService;
use App\Services\DataWaliService;
use App\Services\Whatsapp\KirimAksesWali;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Data santri & wali" (izin santri.kelola): cari & tambah santri, ubah biodata, aktifkan calon,
 * keluarkan santri, kelola wali (tambah/tautkan, lepas, reset password), dan verifikasi perubahan nomor WA wali
 * (izin data_wali.verifikasi). Password wali hanya dikirim ke WhatsApp wali, tidak ditampilkan.
 */
class SantriController extends Controller
{
    public function __construct(private DataSantriService $santriSvc, private DataWaliService $waliSvc, private KirimAksesWali $akses) {}

    public function index(Request $request)
    {
        $ta = TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());
        $q = trim((string) $request->query('q'));
        $status = $request->query('status', 'aktif');
        $kelasId = $request->integer('kelas') ?: null;

        $santri = Santri::query()
            ->with(['wali', 'riwayatKelas' => fn ($r) => $r->where('tahun_ajaran_id', $ta->id)->with('kelas')])
            ->when($status !== 'semua', fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nama', 'like', "%{$q}%")->orWhere('nis', $q)->orWhere('kode_unik', $q)))
            ->when($kelasId, fn ($w) => $w->whereHas('riwayatKelas', fn ($r) => $r->where('tahun_ajaran_id', $ta->id)->where('kelas_id', $kelasId)))
            ->orderBy('nama')->paginate(30)->withQueryString();

        return view('santri.index', [
            'santri' => $santri, 'ta' => $ta, 'q' => $q, 'status' => $status, 'kelasId' => $kelasId,
            'daftarKelas' => Kelas::orderBy('nama')->get(),
            'nomorMenunggu' => $request->user()->hasPermissionTo(Izin::DataWaliVerifikasi->value)
                ? User::whereNotNull('telepon_menunggu')->with('anak')->get() : collect(),
        ]);
    }

    public function create()
    {
        return view('santri.form', ['santri' => new Santri(['jenis_kelamin' => 'laki-laki']), 'daftarKelas' => Kelas::orderBy('nama')->get(),
            'kodeSaran' => $this->santriSvc->kodeUnikBaru()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validasi($request, null) + $request->validate(['kelas_id' => 'required|exists:kelas,id']);
        $ta = TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());
        try {
            $s = $this->santriSvc->tambah(collect($d)->except('kelas_id')->all(), Kelas::findOrFail($d['kelas_id']), $ta, $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withInput()->withErrors(['santri' => $e->getMessage()]);
        }

        return redirect()->route('santri.show', $s)->with('status', "Santri {$s->nama} ditambahkan dengan kode transfer {$s->kode_unik}. Tambahkan walinya di bawah.");
    }

    public function show(Santri $santri)
    {
        $santri->load(['wali', 'riwayatKelas.kelas', 'riwayatKelas.tahunAjaran']);

        return view('santri.show', ['santri' => $santri, 'saldo' => $santri->saldo(), 'waOn' => $this->akses->aktif()]);
    }

    public function edit(Santri $santri)
    {
        return view('santri.form', ['santri' => $santri, 'daftarKelas' => collect(), 'kodeSaran' => null]);
    }

    public function update(Request $request, Santri $santri): RedirectResponse
    {
        $santri->update($this->validasi($request, $santri));

        return redirect()->route('santri.show', $santri)->with('status', 'Biodata disimpan.');
    }

    public function aktifkan(Request $request, Santri $santri): RedirectResponse
    {
        $d = $request->validate(['tanggal_masuk' => 'required|date']);

        return $this->jalankan($santri, fn () => $this->santriSvc->aktifkan($santri, CarbonImmutable::parse($d['tanggal_masuk']), $request->user()),
            "{$santri->nama} sekarang santri aktif; tagihan bulanan terbit mulai bulan ini.");
    }

    public function keluarkan(Request $request, Santri $santri): RedirectResponse
    {
        $d = $request->validate(['alasan' => 'required|string|max:300', 'karena_tunggakan' => 'nullable|boolean']);

        return $this->jalankan($santri, fn () => $this->santriSvc->keluarkan($santri, $d['alasan'], $request->user(), (bool) ($d['karena_tunggakan'] ?? false)),
            "{$santri->nama} dicatat keluar. Kode transfer dibebaskan.");
    }

    public function waliStore(Request $request, Santri $santri): RedirectResponse
    {
        $d = $request->validate([
            'name' => 'required|string|max:100', 'telepon' => 'required|string|max:20', 'hubungan' => ['required', Rule::in(['ayah', 'ibu', 'wali'])],
            'email' => 'nullable|email|max:100', 'nik' => 'nullable|digits:16', 'pekerjaan' => 'nullable|string|max:100', 'alamat' => 'nullable|string|max:300',
        ]);
        try {
            $hasil = $this->waliSvc->tambahAtauTautkan(collect($d)->except('hubungan')->all(), [$santri], $d['hubungan'], $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withInput()->withErrors(['wali' => $e->getMessage()]);
        }
        $pesan = $hasil['akun_baru']
            ? 'Akun wali '.$hasil['wali']->name.' dibuat. '.$this->akses->kirim($hasil['wali'], $hasil['password_awal'])['pesan']
            : $hasil['wali']->name.' sudah punya akun (nomor sama); '.$santri->nama.' ditautkan ke akun itu.';

        return redirect()->route('santri.show', $santri)->with('status', $pesan);
    }

    public function waliLepas(Request $request, Santri $santri, User $wali): RedirectResponse
    {
        return $this->jalankan($santri, fn () => $this->waliSvc->lepasTautan($wali, $santri, $request->user()),
            "{$wali->name} dilepas dari {$santri->nama}.");
    }

    public function waliReset(Request $request, Santri $santri, User $wali, AkunService $akun): RedirectResponse
    {
        abort_unless($wali->adalahWaliDari($santri), 404);
        try {
            $password = $akun->resetPassword($wali, $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['wali' => $e->getMessage()]);
        }

        return redirect()->route('santri.show', $santri)->with('status', 'Password '.$wali->name.' di-reset. '.$this->akses->kirim($wali, $password, 'Password akun portal wali Anda di-reset')['pesan']);
    }

    public function teleponSetujui(Request $request, User $wali): RedirectResponse
    {
        try {
            $this->waliSvc->setujuiTelepon($wali, $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['santri' => $e->getMessage()]);
        }

        return back()->with('status', "Nomor WhatsApp {$wali->name} diganti ke {$wali->telepon}.");
    }

    public function teleponTolak(Request $request, User $wali): RedirectResponse
    {
        $this->waliSvc->tolakTelepon($wali, $request->user());

        return back()->with('status', "Perubahan nomor {$wali->name} ditolak; nomor lama tetap dipakai.");
    }

    private function validasi(Request $request, ?Santri $santri): array
    {
        return $request->validate([
            'nis' => ['required', 'string', 'max:20', Rule::unique('santri', 'nis')->ignore($santri?->id)],
            'nama' => 'required|string|max:100',
            'jenis_kelamin' => ['required', Rule::in(['laki-laki', 'perempuan'])],
            'nisn' => 'nullable|string|max:20', 'nik' => 'nullable|digits:16',
            'tempat_lahir' => 'nullable|string|max:50', 'tanggal_lahir' => 'nullable|date',
            'alamat' => 'nullable|string|max:500', 'asal_sekolah' => 'nullable|string|max:100',
            'tanggal_masuk' => 'nullable|date',
        ]);
    }

    private function jalankan(Santri $santri, callable $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['santri' => $e->getMessage()]);
        }

        return redirect()->route('santri.show', $santri)->with('status', $pesan);
    }
}
