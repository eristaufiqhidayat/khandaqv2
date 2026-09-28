<?php

namespace App\Services\Whatsapp;

use App\Enums\Izin;
use App\Enums\StatusSantri;
use App\Enums\StatusTagihan;
use App\Exceptions\AturanDilanggar;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaPesan;
use App\Models\WaSiaran;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Siaran WhatsApp ke wali santri (pengganti menu "Whatsapp Manager > Kirim Pesan").
 *
 * Bedanya dengan aplikasi lama: tujuan tidak diambil dari buku telepon terpisah (tbl_nama_wa, yang grup
 * "Wali Santri"-nya hanya berisi 2 nomor), tetapi dari nomor wali yang sudah diverifikasi di data santri,
 * dengan filter: semua wali, per kelas, atau wali yang anaknya menunggak. Pengiriman lewat antrean
 * dengan jeda, dan setiap nomor tercatat statusnya (terkirim/diterima/dibaca/gagal).
 */
class SiaranWaService
{
    public const SASARAN = ['semua_wali', 'kelas', 'tunggakan'];

    /** @param null|callable(WaPesan $pesan, int $urutan): void $antrikan */
    public function __construct(
        private PengirimWa $pengirim,
        private $antrikan = null,
    ) {
        $this->antrikan ??= fn (WaPesan $p, int $i) => \App\Jobs\KirimPesanWa::dispatch($p->id)
            ->delay(now()->addSeconds($i * (int) config('khandaq.whatsapp.jeda_detik', 3)));
    }

    public function buat(User $pembuat, string $judul, string $isi, array $sasaran, ?string $template = null): WaSiaran
    {
        Otorisasi::pastikan($pembuat, Izin::WaSiaran);
        $this->pastikanSasaran($sasaran);
        if (trim($isi) === '') {
            throw new AturanDilanggar('Isi pesan tidak boleh kosong.');
        }
        if ($this->pengirim->butuhTemplate() && ! $template) {
            throw new AturanDilanggar('Gateway Meta hanya bisa memulai percakapan dengan template yang sudah disetujui Meta. Pilih template.');
        }

        return WaSiaran::create([
            'judul' => $judul, 'isi' => $isi, 'template' => $template, 'sasaran' => $sasaran,
            'status' => 'draf', 'dibuat_oleh' => $pembuat->id,
        ]);
    }

    /**
     * Daftar tujuan: satu baris per nomor wali.
     *
     * @return array{tujuan: Collection<int, array{user_id:int, telepon:string, nama_wali:string, nama_santri:string}>, tanpa_nomor: int}
     */
    public function tujuan(array $sasaran): array
    {
        $this->pastikanSasaran($sasaran);
        $santri = Santri::query()->where('status', StatusSantri::Aktif->value);

        if ($sasaran['jenis'] === 'kelas') {
            $ta = TahunAjaran::aktif() ?? throw new AturanDilanggar('Belum ada tahun ajaran aktif.');
            $santri->whereHas('riwayatKelas', fn ($q) => $q->where('tahun_ajaran_id', $ta->id)->whereIn('kelas_id', $sasaran['kelas_ids']));
        }
        if ($sasaran['jenis'] === 'tunggakan') {
            $min = max(1, (int) ($sasaran['min_bulan'] ?? 1));
            $hariIni = CarbonImmutable::now()->toDateString();
            $santri->whereIn('santri.id', DB::table('tagihan')
                ->join('jenis_tagihan', 'jenis_tagihan.id', '=', 'tagihan.jenis_tagihan_id')
                ->where('jenis_tagihan.dihitung_tunggakan', true)
                ->whereIn('tagihan.status', StatusTagihan::terbuka())
                ->where('tagihan.jatuh_tempo', '<', $hariIni)
                ->groupBy('tagihan.santri_id')->havingRaw('COUNT(*) >= ?', [$min])
                ->select('tagihan.santri_id'));
        }

        $baris = DB::table('wali_santri')
            ->join('users', 'users.id', '=', 'wali_santri.user_id')
            ->join('santri', 'santri.id', '=', 'wali_santri.santri_id')
            ->whereIn('wali_santri.santri_id', $santri->select('santri.id'))
            ->where('users.aktif', true)
            ->orderBy('users.id')
            ->get(['users.id AS user_id', 'users.name AS nama_wali', 'users.telepon', 'santri.nama AS nama_santri']);

        $tanpaNomor = $baris->whereNull('telepon')->pluck('user_id')->unique()->count();
        $tujuan = $baris->whereNotNull('telepon')->groupBy('telepon')->map(fn (Collection $g) => [
            'user_id' => $g->first()->user_id,
            'telepon' => $g->first()->telepon,
            'nama_wali' => $g->first()->nama_wali,
            'nama_santri' => $g->pluck('nama_santri')->unique()->join(', ', ' dan '),
        ])->values();

        return ['tujuan' => $tujuan, 'tanpa_nomor' => $tanpaNomor];
    }

    public function kirim(WaSiaran $siaran, User $pengirim): WaSiaran
    {
        Otorisasi::pastikan($pengirim, Izin::WaSiaran);
        if ($siaran->status !== 'draf') {
            throw new AturanDilanggar('Siaran ini sudah dikirim atau dibatalkan.');
        }
        $tujuan = $this->tujuan($siaran->sasaran)['tujuan'];
        if ($tujuan->isEmpty()) {
            throw new AturanDilanggar('Tidak ada wali dengan nomor WhatsApp pada sasaran ini.');
        }

        $pesan = DB::transaction(function () use ($siaran, $pengirim, $tujuan) {
            $siaran->update([
                'status' => 'mengirim', 'jumlah_tujuan' => $tujuan->count(),
                'dikirim_oleh' => $pengirim->id, 'dikirim_pada' => CarbonImmutable::now(),
            ]);

            return $tujuan->map(fn (array $t) => $siaran->pesan()->create([
                'user_id' => $t['user_id'], 'telepon' => $t['telepon'], 'status' => 'antri',
                'isi' => strtr($siaran->isi, ['{nama_wali}' => $t['nama_wali'], '{nama_santri}' => $t['nama_santri']]),
            ]));
        });
        foreach ($pesan->values() as $i => $p) {
            ($this->antrikan)($p, $i);
        }

        return $siaran->refresh();
    }

    public function batalkan(WaSiaran $siaran, User $user): WaSiaran
    {
        Otorisasi::pastikan($user, Izin::WaSiaran);
        if (in_array($siaran->status, ['selesai', 'dibatalkan'], true)) {
            throw new AturanDilanggar('Siaran ini sudah selesai.');
        }
        // Pesan yang sudah terkirim tetap tercatat; yang masih antri tidak jadi dikirim.
        $siaran->pesan()->where('status', 'antri')->update(['status' => 'gagal', 'galat' => 'Dibatalkan sebelum terkirim']);
        $siaran->update(['status' => 'dibatalkan']);
        $siaran->segarkanJumlah();

        return $siaran;
    }

    private function pastikanSasaran(array $s): void
    {
        if (! in_array($s['jenis'] ?? null, self::SASARAN, true)) {
            throw new AturanDilanggar('Sasaran siaran tidak dikenal.');
        }
        if ($s['jenis'] === 'kelas' && empty($s['kelas_ids'])) {
            throw new AturanDilanggar('Pilih minimal satu kelas.');
        }
    }
}
