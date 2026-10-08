<?php

namespace App\Services\Migrasi;

use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Models\MigrasiRun;
use App\Models\Semester;
use App\Models\TutupBuku;
use App\Models\User;
use App\Services\AkunService;
use App\Services\PendaftaranService;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Modul "Sinkronisasi data lama": satu klik menyalin SEMUA data dari database aplikasi lama
 * (lembaha1_sino) ke tabel baru, MENGGANTI hasil salinan sebelumnya. Bisa dijalankan berulang
 * selama masa paralel; terkunci setelah mode produksi atau setelah ada tutup buku.
 *
 * Urutan: kosongkan -> periode -> kelas -> santri -> wali -> riwayat kelas -> akun/pengusul -> tarif
 *         -> beasiswa -> buku tabungan (+ tagihan per potongan) -> tabel samping (DSB, DU, PTS, PAS,
 *         laundry, buku) -> pengeluaran -> mutasi BSI -> raport -> foto santri -> rekonsiliasi saldo.
 *
 * Aturan penting:
 *  - Buku tabungan (tbl_tabungan_transaksi) adalah sumber utama: setiap baris menjadi satu baris
 *    tabungan_mutasi dengan legacy_notransaksi yang sama. Setiap potongan (TDSPP, TDLDR, ...) juga
 *    menjadi satu tagihan berstatus lunas, supaya riwayat pembayaran utuh.
 *  - Tabel samping sebagian besar hanya salinan potongan tabungan. Baris yang cocok (santri, kode,
 *    nominal, tanggal) TIDAK ditambahkan lagi, hanya dipakai untuk memberi label jenis yang tepat
 *    (mis. TDADM "Daftar ulang" -> tagihan DU). Baris yang tidak cocok = dibayar di luar tabungan:
 *    dicatat sebagai setoran + pembayaran (saldo tetap, riwayat & dana lengkap).
 *  - Semua dalam satu transaksi database: bila gagal di tengah, data baru tidak berubah.
 */
class MigrasiDataLama
{
    private ConnectionInterface $lama;

    private CarbonImmutable $now;

    private int $adminId;

    /** @var array<string, array{sumber:int, masuk:int, dilewati:int, catatan:list<string>}> */
    private array $ringkasan = [];

    private array $taByTahun = [];      // tahun_mulai => ta id

    private array $semByTaNomor = [];   // "ta|nomor" => [id, mulai]

    private array $semByPeriode = [];   // id_periode lama => [semester id, ta id, mulai]

    private array $kelasMap = [];       // id_kelas lama => kelas id

    private array $kelasNama = [];      // kelas id => nama

    private array $santriMap = [];      // id_siswa lama => santri id

    private array $jenisId = [];        // kode jenis tagihan => id

    private array $jenisBulanan = [];   // kode jenis tagihan berfrekuensi bulanan (SPP, laundry, kesehatan) => true

    private array $periodeTerpakai = []; // "santri|jenis|ta" => [indeks bulan 0..11 => true]

    private array $masukSantri = [];    // santri id => tanggal masuk (Y-m-d) atau null

    private array $rekeningId = [];     // KAS/BSI => id

    private array $danaId = [];         // kode dana => id

    private array $debitIndex = [];     // "santri|kode|rp|tgl" => [tagihan id, ...] untuk pencocokan tabel samping

    private array $debitIndexTgl = [];  // "santri|kode|tgl" => [tagihan id, ...] (pencocokan tanpa nominal, mis. laundry)

    private array $dipakai = [];        // tagihan id yang sudah dipasangkan dengan baris tabel samping

    private ?int $taAktif = null;

    /** @var callable(string $path, string $isi): void */
    private $simpanFile;

    private ?MigrasiRun $run = null;

    private int $jumlahTahap = 1;

    private int $urutTahap = 0;

    private string $namaTahap = '';

    private float $terakhirDicatat = 0;

    public function __construct(
        private string $koneksiLama = 'lama',
        private string $mode = 'paralel',
        ?callable $simpanFile = null,
        private ?string $koneksiLoginLama = null, // lembaha1_igni399 (Myth/Auth); null = password wali dibuat acak
    ) {
        $this->simpanFile = $simpanFile ?? fn (string $path, string $isi) => \Illuminate\Support\Facades\Storage::disk('local')->put($path, $isi);
    }

    public function pastikanBoleh(User $admin): void
    {
        Otorisasi::pastikan($admin, Izin::MigrasiJalankan);
        if ($this->mode !== 'paralel') {
            throw new AturanDilanggar('Sinkronisasi hanya bisa dijalankan pada masa paralel. Aplikasi sudah mode produksi.');
        }
        if (TutupBuku::exists()) {
            throw new AturanDilanggar('Sudah ada tutup buku di aplikasi baru; sinkronisasi akan menimpa data yang sudah ditutup.');
        }
    }

    public function jalankan(User $admin, ?MigrasiRun $run = null): MigrasiRun
    {
        $this->pastikanBoleh($admin);
        self::longgarkanMemori();
        $this->adminId = $admin->id;
        $this->now = CarbonImmutable::now();
        $this->lama = DB::connection($this->koneksiLama);
        $this->run = $run ?? MigrasiRun::create(['status' => 'antri', 'dijalankan_oleh' => $admin->id]);
        $this->run->update(['status' => 'berjalan', 'mulai_pada' => $this->now, 'persen' => 0, 'galat' => null]);

        $tahap = [
            'Mengosongkan salinan lama' => 'kosongkan', 'Tahun ajaran & semester' => 'periode', 'Kelas' => 'kelas',
            'Santri' => 'santri', 'Wali & akun portal' => 'wali', 'Riwayat kelas' => 'riwayatKelas',
            'Akun biaya & pengusul' => 'akunPengusul', 'Tarif' => 'tarif', 'Beasiswa' => 'beasiswa',
            'Buku tabungan' => 'tabungan', 'DSB, Daftar Ulang, PTS, PAS, laundry, buku' => 'tabelSamping',
            'Pengeluaran per dana' => 'pengeluaran', 'Tagihan bulan berjalan' => 'tagihanBerjalan', 'Mutasi BSI' => 'mutasiBsi', 'Raport' => 'raport', 'Foto santri' => 'fotoSantri', 'Kalender akademik' => 'kalender',
        ];
        try {
            DB::transaction(function () use ($tahap) {
                $this->muatReferensi();
                $this->jumlahTahap = count($tahap) + 1; // + rekonsiliasi
                $this->urutTahap = 0;
                foreach ($tahap as $nama => $metode) {
                    $this->namaTahap = $nama;
                    $this->run->catatProgres($nama, $this->persenTahap(0));
                    $this->{$metode}();
                    $this->urutTahap++;
                }
            });
            $this->run->catatProgres('Rekonsiliasi saldo', $this->persenTahap(0));
            $this->run->update([
                'status' => 'selesai', 'tahap' => 'Rekonsiliasi saldo', 'persen' => 100,
                'ringkasan' => $this->ringkasan, 'rekonsiliasi' => $this->rekonsiliasi(),
                'selesai_pada' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $e) {
            $this->run->update(['status' => 'gagal', 'galat' => $e->getMessage(), 'ringkasan' => $this->ringkasan, 'selesai_pada' => CarbonImmutable::now()]);
            throw $e;
        }

        return $this->run->refresh();
    }

    /** Batas bawaan PHP di banyak server hanya 128 MB; sinkronisasi membaca ~95 ribu transaksi. Naikkan ke 512 MB bila lebih kecil. */
    public static function longgarkanMemori(string $target = '512M'): void
    {
        $keByte = function (string $v): int {
            $n = (int) $v;

            return match (strtoupper(substr(trim($v), -1))) {
                'G' => $n * 1024 ** 3, 'M' => $n * 1024 ** 2, 'K' => $n * 1024, default => $n,
            };
        };
        $batas = (string) ini_get('memory_limit');
        if ($batas !== '-1' && $keByte($batas) < $keByte($target)) {
            @ini_set('memory_limit', $target);
        }
    }

    /**
     * Isi file dari kolom BLOB aplikasi lama. Aplikasi lama menyimpan raport sebagai teks base64
     * (base64_encode(file_get_contents(...)), ditampilkan lewat data:application/pdf;base64,...), bukan byte PDF.
     * Terima keduanya: teks base64 (dengan/tanpa awalan data:) di-decode; byte mentah dipakai apa adanya.
     *
     * @return array{0: string, 1: string, 2: string} [isi, mime, ekstensi]
     */
    public static function isiFileLama(string $mentah): array
    {
        $isi = $mentah;
        $teks = ltrim($mentah);
        if (! self::tandaFile($teks)) {
            $b64 = preg_replace('/^data:[^;,]*;base64,/i', '', $teks);
            $hasil = base64_decode(preg_replace('/\s+/', '', $b64), true);
            if ($hasil !== false && self::tandaFile($hasil)) {
                $isi = $hasil;
            }
        }

        return match (self::tandaFile($isi)) {
            'png' => [$isi, 'image/png', 'png'],
            'jpg' => [$isi, 'image/jpeg', 'jpg'],
            default => [$isi, 'application/pdf', 'pdf'],
        };
    }

    private static function tandaFile(string $isi): ?string
    {
        return match (true) {
            str_starts_with($isi, '%PDF') => 'pdf',
            str_starts_with($isi, "\x89PNG") => 'png',
            str_starts_with($isi, "\xFF\xD8\xFF") => 'jpg',
            default => null,
        };
    }

    /** @var array<string, list<string>> */
    private array $kolomBaca = [];

    /**
     * Query ke tabel lama TANPA kolom file (foto santri, bukti transfer, dst.). Kolom BLOB bisa berukuran MB per baris
     * dan driver MySQL menampung seluruh hasil query di memori PHP, sehingga server 128 MB kehabisan memori.
     * Sinkronisasi tidak memakai kolom-kolom itu (raport dibaca terpisah, satu file per query).
     */
    private function baca(string $tabel): \Illuminate\Database\Query\Builder
    {
        $this->kolomBaca[$tabel] ??= collect(\Illuminate\Support\Facades\Schema::connection($this->lama->getName())->getColumns($tabel))
            ->reject(fn (array $k) => preg_match('/blob|binary/i', (string) ($k['type_name'] ?? '').' '.($k['type'] ?? ''))
                || preg_match('/^(image|foto|photo|bukti\d*|bukti_transfer)$/i', $k['name']))
            ->pluck('name')->all();

        return $this->lama->table($tabel)->select($this->kolomBaca[$tabel]);
    }

    /** Persen keseluruhan untuk tahap saat ini, dengan $fraksi (0..1) kemajuan di dalam tahap itu. */
    private function persenTahap(float $fraksi): int
    {
        return (int) floor(($this->urutTahap + max(0, min(1, $fraksi))) / $this->jumlahTahap * 100);
    }

    /** Kemajuan di dalam tahap yang panjang (mis. ~95 ribu transaksi tabungan); dicatat paling sering tiap 2 detik. */
    private function kemajuan(int $selesai, int $total): void
    {
        if (! $this->run || $total <= 0 || microtime(true) - $this->terakhirDicatat < 2) {
            return;
        }
        $this->terakhirDicatat = microtime(true);
        $this->run->catatProgres($this->namaTahap.' ('.number_format($selesai, 0, ',', '.').' / '.number_format($total, 0, ',', '.').')', $this->persenTahap($selesai / $total));
    }

    // ------------------------------------------------------------------ tahap

    /** @var array<int, object> legacy_id_orangtua -> tautan Google sebelum sinkron */
    private array $googleLama = [];

    private function kosongkan(): void
    {
        // Anak dulu, induk belakangan. Akun staf, peran/izin, dana, rekening, jenis tagihan tidak disentuh.
        // Modul guru: nilai & penugasan memakai id santri/kelas/semester yang dibuat ulang. Mapel, soal, catatan harian tetap.
        foreach (['nilai', 'guru_mengajar'] as $t) {
            if (\Illuminate\Support\Facades\Schema::hasTable($t)) {
                DB::table($t)->delete();
            }
        }
        foreach (['peringatan', 'raport', 'pengeluaran', 'tabungan_mutasi', 'bank_mutasi', 'bank_impor', 'tagihan',
            'keringanan', 'pengecualian_potongan', 'jeda_potongan', 'tarif', 'pendaftaran', 'wali_santri', 'santri_kelas'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('santri')->delete();
        // Akun Google yang sudah ditautkan wali (Masuk dengan Google) dibawa ke akun hasil sinkron berikutnya.
        $this->googleLama = DB::table('users')->whereNotNull('legacy_id_orangtua')->whereNotNull('firebase_uid')
            ->get(['legacy_id_orangtua', 'firebase_uid', 'email_google'])->keyBy('legacy_id_orangtua')->all();
        $wali = DB::table('users')->whereNotNull('legacy_id_orangtua')->pluck('id');
        if ($wali->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('model_has_roles')) {
            DB::table('model_has_roles')->whereIn('model_id', $wali)->where('model_type', User::class)->delete();
        }
        DB::table('users')->whereIn('id', $wali)->delete();
        foreach (['semester', 'tahun_ajaran', 'kelas', 'akun', 'pengusul'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('kalender_akademik')->whereNotNull('legacy_no')->delete(); // kegiatan yang diisi di aplikasi baru tetap
    }

    private function periode(): void
    {
        $rows = $this->baca('setup_periode')->orderBy('id_periode')->get();
        foreach ($rows as $r) {
            if (! preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', trim((string) $r->tahun_ajaran), $m)) {
                $this->lewati('setup_periode', "id {$r->id_periode}: tahun ajaran '{$r->tahun_ajaran}' tidak dikenali");

                continue;
            }
            $ta = $this->taId((int) $m[1]);
            $nomor = strtolower((string) $r->semester) === 'genap' ? 2 : 1;
            [$semId, $mulai] = $this->semByTaNomor["{$ta}|{$nomor}"];
            DB::table('semester')->where('id', $semId)->update([
                // Nama di data lama tidak seragam ("Semester 1", "semester 1", "Semester II") dan tanpa tahun: pakai nama baku.
                'nama' => Semester::namaBaku($nomor, $m[1].'/'.$m[2]),
                'aktif' => $r->status_aktif === 'yes', 'legacy_id_periode' => $r->id_periode,
            ]);
            if ($r->status_aktif === 'yes') {
                DB::table('tahun_ajaran')->where('id', $ta)->update(['aktif' => true]);
                $this->taAktif = $ta;
            }
            $this->semByPeriode[$r->id_periode] = [$semId, $ta, $mulai];
            $this->masuk('setup_periode');
        }
        $this->sumber('setup_periode', count($rows));
    }

    private function kelas(): void
    {
        $rows = $this->baca('setup_kelas')->get();
        foreach ($rows as $r) {
            $nama = trim((string) $r->nama_kelas);
            preg_match('/\d+/', $nama, $m);
            $jk = str_contains(strtoupper($nama), 'PUTRI') ? 'putri' : (str_contains(strtoupper($nama), 'PUTRA') ? 'putra' : null);
            $id = DB::table('kelas')->insertGetId([
                'nama' => $nama, 'tingkat' => (int) ($m[0] ?? 1), 'jenis_kelamin' => $jk, 'aktif' => true,
                'legacy_id_kelas' => $r->id_kelas, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $this->kelasMap[$r->id_kelas] = $id;
            $this->kelasNama[$id] = $nama;
            $this->masuk('setup_kelas');
        }
        $this->sumber('setup_kelas', count($rows));
    }

    private function santri(): void
    {
        $rows = $this->baca('data_siswa')->orderBy('id_siswa')->get();
        $nisDipakai = [];
        $kodeDipakai = [];
        foreach ($rows as $r) {
            $nis = trim((string) $r->nis);
            if ($nis === '' || isset($nisDipakai[$nis])) {
                $this->catatan('data_siswa', 'NIS kosong/ganda diganti L<id_siswa>');
                $nis = 'L'.$r->id_siswa;
            }
            $nisDipakai[$nis] = true;
            $kode = preg_match('/^\d{3}$/', trim((string) $r->unik)) ? trim((string) $r->unik) : null;
            if ($kode && isset($kodeDipakai[$kode])) {
                $kode = null;
            }
            if ($kode) {
                $kodeDipakai[$kode] = true;
            }
            $status = match ($r->locked) { 'no' => 'aktif', 'baru' => 'calon', default => 'alumni' };
            $this->santriMap[$r->id_siswa] = DB::table('santri')->insertGetId([
                'legacy_id_siswa' => $r->id_siswa, 'nis' => $nis, 'nisn' => $this->kosongNull($r->nisn),
                'nama' => trim((string) $r->nama_siswa) ?: 'Tanpa nama '.$r->id_siswa,
                'jenis_kelamin' => $r->kelamin ?: 'laki-laki', 'tempat_lahir' => $this->kosongNull($r->tempat_lahir),
                'tanggal_lahir' => $this->tgl($r->tanggal_lahir), 'alamat' => $this->kosongNull($r->alamat_siswa),
                'telepon' => $this->kosongNull($r->telpon_siswa), 'tanggal_masuk' => $this->tgl($r->tanggal_masuk),
                'status' => $status, 'kode_unik' => $kode, 'catatan' => $this->kosongNull($r->keterangan),
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $this->masukSantri[$this->santriMap[$r->id_siswa]] = $this->tgl($r->tanggal_masuk);
            $this->masuk('data_siswa');
        }
        // Santri aktif tanpa kode unik mendapat kode baru agar transfer BSI bisa dicocokkan.
        $baru = 0;
        foreach (DB::table('santri')->where('status', 'aktif')->whereNull('kode_unik')->orderBy('id')->pluck('id') as $id) {
            for ($k = 101; $k <= 999; $k++) {
                if ($k % 100 !== 0 && ! isset($kodeDipakai[(string) $k])) {
                    DB::table('santri')->where('id', $id)->update(['kode_unik' => (string) $k]);
                    $kodeDipakai[(string) $k] = true;
                    $baru++;
                    break;
                }
            }
        }
        if ($baru) {
            $this->catatan('data_siswa', "{$baru} santri aktif dibuatkan kode unik transfer baru");
        }
        $this->sumber('data_siswa', count($rows));
    }

    private function wali(): void
    {
        $ortu = $this->baca('data_orangtua')->orderBy('id_orangtua')->get();
        $userLama = [];
        $username = DB::table('users')->pluck('id', 'username')->all();
        $email = DB::table('users')->pluck('id', 'email')->all();
        // Akun login wali di aplikasi lama: users.username = data_orangtua.username, ortu = '1'.
        $loginLama = $this->koneksiLoginLama
            ? DB::connection($this->koneksiLoginLama)->table('users')->where('ortu', '1')->whereNull('deleted_at')
                ->where('active', 1)->pluck('password_hash', 'username')->mapWithKeys(fn ($h, $u) => [mb_strtolower(trim((string) $u)) => $h])->all()
            : [];
        $roleWali = \Illuminate\Support\Facades\Schema::hasTable('roles')
            ? DB::table('roles')->where('name', 'wali_santri')->value('id') : null;
        foreach ($ortu as $o) {
            $tel = PendaftaranService::normalTelepon((string) $o->telpon_orangtua);
            $un = ($tel !== '' && ! isset($username[$tel])) ? $tel : 'wali'.$o->id_orangtua;
            $em = trim((string) $o->email);
            $em = ($em !== '' && ! isset($email[$em])) ? $em : "wali{$o->id_orangtua}@wali.khandaq";
            $id = DB::table('users')->insertGetId([
                'name' => trim((string) $o->nama_orangtua) ?: 'Wali '.$o->id_orangtua, 'username' => $un, 'email' => $em,
                'telepon' => $tel ?: null, 'alamat' => $this->kosongNull($o->alamat_orangtua), 'pekerjaan' => $this->kosongNull($o->pekerjaan),
                // Password acak yang tidak diketahui siapa pun. Wali yang punya akun di aplikasi lama tetap masuk dengan
                // password lamanya (password_lama, lihat AkunService::cocokkanPassword); sisanya menerima password
                // sementara lewat WhatsApp saat go-live, bukan setiap sinkronisasi.
                'password' => AkunService::hash(AkunService::acak()), 'aktif' => true,
                'password_lama' => $hashLama = $loginLama[mb_strtolower(trim((string) $o->username))] ?? null,
                'wajib_ganti_password' => $hashLama === null,
                'legacy_id_orangtua' => $o->id_orangtua, 'created_at' => $this->now, 'updated_at' => $this->now,
                'firebase_uid' => $this->googleLama[$o->id_orangtua]->firebase_uid ?? null,
                'email_google' => $this->googleLama[$o->id_orangtua]->email_google ?? null,
            ]);
            $username[$un] = $email[$em] = $id;
            $userLama[$o->id_orangtua] = [$id, match ($o->status_keluarga) { 'bapak' => 'ayah', 'ibu' => 'ibu', default => 'wali' }];
            if ($roleWali) {
                DB::table('model_has_roles')->insert(['role_id' => $roleWali, 'model_type' => User::class, 'model_id' => $id]);
            }
            $this->masuk('data_orangtua');
        }
        $this->sumber('data_orangtua', count($ortu));
        if ($this->koneksiLoginLama) {
            $dibawa = DB::table('users')->whereNotNull('legacy_id_orangtua')->whereNotNull('password_lama')->count();
            $this->catatan('data_orangtua', "{$dibawa} wali membawa password dari aplikasi lama; ".(count($ortu) - $dibawa).' wali tanpa akun lama (password sementara saat go-live)');
            $this->catatan('data_orangtua', (count($loginLama) - $dibawa).' akun wali di aplikasi lama tidak punya data orang tua (tidak disalin)');
        }

        $akses = $this->baca('tbl_akses_ortu')->get();
        $sudah = [];
        foreach ($akses as $a) {
            $u = $userLama[$a->id_orangtua] ?? null;
            $s = $this->santriMap[$a->id_siswa] ?? null;
            if (! $u || ! $s) {
                $this->lewati('tbl_akses_ortu', 'wali atau santri tidak ada di data lama');

                continue;
            }
            if (isset($sudah["{$u[0]}|{$s}"])) {
                $this->lewati('tbl_akses_ortu', 'tautan ganda');

                continue;
            }
            $sudah["{$u[0]}|{$s}"] = true;
            DB::table('wali_santri')->insert(['user_id' => $u[0], 'santri_id' => $s, 'hubungan' => $u[1], 'created_at' => $this->now, 'updated_at' => $this->now]);
            $this->masuk('tbl_akses_ortu');
        }
        $this->sumber('tbl_akses_ortu', count($akses));
    }

    private function riwayatKelas(): void
    {
        $sudah = [];
        $tulis = function (string $tabel, $idSiswa, $idKelas, ?int $ta) use (&$sudah) {
            $s = $this->santriMap[$idSiswa] ?? null;
            $k = $this->kelasMap[$idKelas] ?? null;
            if (! $s || ! $k || ! $ta) {
                $this->lewati($tabel, 'santri/kelas/periode tidak dikenal');

                return;
            }
            if (isset($sudah["{$s}|{$ta}"])) {
                $this->lewati($tabel, 'sudah ada kelas untuk tahun ajaran itu');

                return;
            }
            $sudah["{$s}|{$ta}"] = true;
            DB::table('santri_kelas')->insert(['santri_id' => $s, 'kelas_id' => $k, 'tahun_ajaran_id' => $ta, 'created_at' => $this->now, 'updated_at' => $this->now]);
            $this->masuk($tabel);
        };
        // Kelas sekarang (tbl_ruangan) untuk tahun ajaran aktif lebih diutamakan.
        $now = $this->baca('tbl_ruangan')->get();
        foreach ($now as $r) {
            $tulis('tbl_ruangan', $r->id_siswa, $r->id_kelas, $this->taAktif);
        }
        $this->sumber('tbl_ruangan', count($now));
        $hist = $this->baca('tbl_ruangan_periode')->get();
        foreach ($hist as $r) {
            $tulis('tbl_ruangan_periode', $r->id_siswa, $r->id_kelas, $this->semByPeriode[$r->id_periode][1] ?? null);
        }
        $this->sumber('tbl_ruangan_periode', count($hist));
    }

    private function akunPengusul(): void
    {
        foreach (['tbl_akun' => ['akun', 'kode_akun', 'nama_akun'], 'tbl_pengusul' => ['pengusul', 'kode_pengusul', 'nama_pengusul']] as $src => [$dst, $k, $n]) {
            $rows = $this->baca($src)->get();
            $sudah = [];
            foreach ($rows as $r) {
                $kode = trim((string) $r->{$k});
                if ($kode === '' || isset($sudah[strtolower($kode)])) {
                    $this->lewati($src, 'kode kosong/ganda');

                    continue;
                }
                $sudah[strtolower($kode)] = true;
                DB::table($dst)->insert(['kode' => $kode, 'nama' => trim((string) $r->{$n}) ?: $kode, 'created_at' => $this->now, 'updated_at' => $this->now]);
                $this->masuk($src);
            }
            $this->sumber($src, count($rows));
        }
    }

    private function tarif(): void
    {
        $rows = $this->baca('tbl_tarif_spp')->orderBy('no')->get();
        $spp = $this->jenisId['SPP'];
        foreach ($rows as $r) {
            $sem = $this->semByPeriode[(int) $r->periode] ?? null;
            $k = $this->kelasMap[(int) $r->id_kelas] ?? null;
            if (! $sem || ! $k) {
                $this->lewati('tbl_tarif_spp', 'periode/kelas tidak dikenal');

                continue;
            }
            DB::table('tarif')->updateOrInsert(
                ['jenis_tagihan_id' => $spp, 'tahun_ajaran_id' => $sem[1], 'kelas_id' => $k, 'berlaku_mulai' => $sem[2]],
                ['nominal' => (int) $r->tarif, 'keterangan' => 'Migrasi tbl_tarif_spp #'.$r->no, 'dibuat_oleh' => $this->adminId, 'created_at' => $this->now, 'updated_at' => $this->now],
            );
            $this->masuk('tbl_tarif_spp');
        }
        $this->sumber('tbl_tarif_spp', count($rows));
        // Tarif yang dulu di-hardcode di kode (C_DataTransaksiTabungan & cekdata_helper).
        if ($this->taAktif) {
            $mulai = DB::table('tahun_ajaran')->where('id', $this->taAktif)->value('mulai');
            foreach (['INFAK' => 15000, 'LAUNDRY' => 100000, 'KESEHATAN' => 50000] as $kode => $n) {
                DB::table('tarif')->insert(['jenis_tagihan_id' => $this->jenisId[$kode], 'tahun_ajaran_id' => $this->taAktif, 'kelas_id' => null,
                    'berlaku_mulai' => $mulai, 'nominal' => $n, 'keterangan' => 'Nilai hardcode aplikasi lama', 'dibuat_oleh' => $this->adminId,
                    'created_at' => $this->now, 'updated_at' => $this->now]);
            }
            $this->catatan('tbl_tarif_spp', 'Tarif infak/laundry/kesehatan dari kode lama ditambahkan untuk tahun ajaran aktif');
        }
    }

    private function beasiswa(): void
    {
        $rows = $this->baca('tbl_beasiswa')->get();
        foreach ($rows as $r) {
            if ($r->peserta !== 'yes') {
                $this->lewati('tbl_beasiswa', 'bukan peserta aktif');

                continue;
            }
            $s = $this->santriMap[(int) $r->id_siswa] ?? null;
            if (! $s || ! $this->taAktif) {
                $this->lewati('tbl_beasiswa', 'santri tidak dikenal');

                continue;
            }
            // Di aplikasi lama, kolom rp = SPP yang DIBAYAR santri; di aplikasi baru disimpan sebagai potongan.
            $nominal = null;
            $persen = null;
            if ((int) $r->rp > 0) {
                $kelas = DB::table('santri_kelas')->where('santri_id', $s)->where('tahun_ajaran_id', $this->taAktif)->value('kelas_id');
                $tarif = (int) DB::table('tarif')->where('jenis_tagihan_id', $this->jenisId['SPP'])->where('tahun_ajaran_id', $this->taAktif)
                    ->where('kelas_id', $kelas)->orderByDesc('berlaku_mulai')->value('nominal');
                $nominal = max(0, $tarif - (int) $r->rp);
            } else {
                $persen = (float) $r->persen;
            }
            DB::table('keringanan')->insert([
                'jenis' => 'beasiswa', 'santri_id' => $s, 'tahun_ajaran_id' => $this->taAktif, 'jenis_tagihan_id' => $this->jenisId['SPP'],
                'persen' => $persen, 'nominal' => $nominal, 'alasan' => 'Migrasi tbl_beasiswa #'.$r->id_beasiswa, 'status' => 'disetujui',
                'diajukan_oleh' => $this->adminId, 'diputuskan_oleh' => $this->adminId, 'diputuskan_pada' => $this->now,
                'catatan_keputusan' => 'Beasiswa yang sudah berlaku di aplikasi lama', 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $this->masuk('tbl_beasiswa');
        }
        $this->sumber('tbl_beasiswa', count($rows));
    }

    private function tabungan(): void
    {
        $kodeArah = $this->lama->table('tbl_kode_transaksi')->pluck('kode_tran', 'kode')->all();
        $buku = $this->lama->table('tbl_tabungan_siswa')->pluck('kode_siswa', 'kode_tab')->all();
        $total = 0;
        $tglSalah = 0;
        $tanpaKode = 0;
        $semua = $this->lama->table('tbl_tabungan_transaksi')->count();
        $this->baca('tbl_tabungan_transaksi')->orderBy('notransaksi')
            ->chunk(1000, function ($rows) use ($kodeArah, $buku, &$total, &$tglSalah, &$tanpaKode, $semua) {
                $this->kemajuan($total, $semua);
                $tagihan = [];
                $mutasi = [];
                foreach ($rows as $r) {
                    $total++;
                    $idSiswa = $buku[$r->kode_tab_trans] ?? null;
                    $s = $idSiswa !== null ? ($this->santriMap[(int) $idSiswa] ?? null) : null;
                    if (! $s) {
                        $this->lewati('tbl_tabungan_transaksi', 'buku tabungan/santri tidak ditemukan');

                        continue;
                    }
                    $tgl = $this->tglJam($r->tanggal);
                    if (! $tgl) {
                        $tglSalah++;
                        $tgl = '2021-01-01 00:00:00';
                    }
                    $kode = (string) $r->kodetransaksi;
                    $arah = match ($kodeArah[$kode] ?? null) { 'K' => 'kredit', 'D' => 'debit', default => null };
                    $status = 'terverifikasi';
                    $ketTambahan = '';
                    if ($arah === null) {
                        // Kode tanpa arah (mis. TDKOR) tidak pernah dihitung ke saldo oleh aplikasi lama.
                        // Baris tetap disalin untuk jejak audit, tetapi tidak dihitung agar saldo wali tidak berubah.
                        $tanpaKode++;
                        $arah = str_starts_with($kode, 'TK') ? 'kredit' : 'debit';
                        $status = 'ditolak';
                        $ketTambahan = ' [tidak dihitung di aplikasi lama: kode tidak terdaftar]';
                    }
                    [$jenis, $rek, $jenisTagihan] = $this->petaKode($kode, (string) $r->keterangan_trans);
                    $ref = 'tbl_tabungan_transaksi:'.$r->notransaksi;
                    if ($jenisTagihan && $status === 'terverifikasi') {
                        $tagihan[$ref] = $this->barisTagihan($s, $jenisTagihan, $tgl, (int) $r->rp,
                            trim((string) $r->keterangan_trans) ?: $kode, $ref);
                    }
                    $mutasi[] = [
                        'santri_id' => $s, 'tanggal' => $tgl, 'arah' => $arah, 'jenis' => $jenis, 'nominal' => max(0, (int) $r->rp),
                        'keterangan' => mb_substr((trim((string) $r->keterangan_trans) ?: $kode).$ketTambahan, 0, 255), 'status' => $status,
                        'rekening_id' => $rek ? $this->rekeningId[$rek] : null, 'tagihan_id' => $ref, 'dana_id' => null,
                        'bukti_path' => $this->kosongNull($r->buktitransfer) ? 'tabungan/legacy/'.$r->buktitransfer : null,
                        'diverifikasi_pada' => $tgl, 'legacy_notransaksi' => $r->notransaksi, 'legacy_kode' => $kode,
                        'created_at' => $this->now, 'updated_at' => $this->now,
                        '_idx' => $jenisTagihan ? "{$s}|{$kode}|".(int) $r->rp.'|'.substr($tgl, 0, 10) : null,
                    ];
                }
                $idTagihan = $this->sisipkanTagihan($tagihan);
                foreach ($mutasi as &$m) {
                    $m['tagihan_id'] = $idTagihan[$m['tagihan_id']] ?? null;
                    if ($m['_idx'] && $m['tagihan_id']) {
                        $this->debitIndex[$m['_idx']][] = $m['tagihan_id'];
                        [$si, $ko, , $tg] = explode('|', $m['_idx']);
                        $this->debitIndexTgl["{$si}|{$ko}|{$tg}"][] = $m['tagihan_id'];
                    }
                    unset($m['_idx']);
                }
                unset($m);
                foreach (array_chunk($mutasi, 500) as $c) {
                    DB::table('tabungan_mutasi')->insert($c);
                }
                $this->masuk('tbl_tabungan_transaksi', count($mutasi));
            });
        $this->sumber('tbl_tabungan_transaksi', $total);
        if ($tglSalah) {
            $this->catatan('tbl_tabungan_transaksi', "{$tglSalah} baris bertanggal tidak valid (mis. 0000-00-00) diberi tanggal 2021-01-01");
        }
        if ($tanpaKode) {
            $this->catatan('tbl_tabungan_transaksi', "{$tanpaKode} baris berkode tanpa arah di tbl_kode_transaksi (mis. TDKOR) disalin dengan status 'tidak dihitung', sama seperti aplikasi lama yang tidak menghitungnya ke saldo");
        }
    }

    private function tabelSamping(): void
    {
        $sumber = [
            // tabel => [kolom santri, kode ledger pasangan, pakai nominal saat cocok, penentu jenis]
            'tbl_pts' => ['id_siswa', ['TDPTS'], true, fn ($r) => 'PTS'],
            'tbl_pas' => ['id_siswa', ['TDPAS'], true, fn ($r) => 'PAS'],
            'tbl_iuran_loundry' => ['id_siswa', ['TDLDR'], false, fn ($r) => 'LAUNDRY'],
            'tbl_daftar_ulang2' => ['id_siswa', ['TDADM'], true, fn ($r) => $r->jenis === 'du' ? 'DU' : 'BUKU'],
            'tbl_uang_buku' => ['id_siswa', ['TDADM'], true, fn ($r) => 'BUKU'],
            'tbl_dsb' => ['id_siswa', ['TDADM'], true, fn ($r) => 'DSB'],
        ];
        foreach ($sumber as $tabel => [$kolSantri, $kodePasangan, $pakaiRp, $jenisDari]) {
            $rows = $this->baca($tabel)->orderBy('no')->get();
            $cocok = 0;
            $luar = 0;
            foreach ($rows as $r) {
                $s = $this->santriMap[(int) $r->{$kolSantri}] ?? null;
                $rp = (int) $r->rp;
                $tgl = $this->tgl($r->tanggal);
                if (! $s || $rp <= 0) {
                    $this->lewati($tabel, ! $s ? 'santri tidak dikenal' : 'nominal 0');

                    continue;
                }
                $jenis = $jenisDari($r);
                // 1) Salinan potongan tabungan? Pakai baris ledger itu, beri label jenis yang tepat.
                $id = $tgl ? $this->ambilPasangan($s, $kodePasangan, $pakaiRp ? $rp : null, $tgl) : null;
                if ($id) {
                    DB::table('tagihan')->where('id', $id)->update(['jenis_tagihan_id' => $this->jenisId[$jenis]]);
                    $cocok++;
                    $this->masuk($tabel);

                    continue;
                }
                // 2) Dibayar di luar tabungan: setoran + pembayaran pada tanggal yang sama (saldo tidak berubah).
                $tglJam = ($tgl ?? '2021-01-01').' 00:00:00';
                $ref = "{$tabel}:{$r->no}";
                $ket = trim((string) ($r->keterangan ?? '')) ?: $jenis;
                $idT = $this->sisipkanTagihan([$ref => $this->barisTagihan($s, $jenis, $tglJam, $rp, $ket, $ref)])[$ref];
                $dasar = ['santri_id' => $s, 'tanggal' => $tglJam, 'nominal' => $rp, 'status' => 'terverifikasi', 'diverifikasi_pada' => $tglJam,
                    'created_at' => $this->now, 'updated_at' => $this->now];
                DB::table('tabungan_mutasi')->insert([
                    $dasar + ['arah' => 'kredit', 'jenis' => 'setoran_transfer', 'keterangan' => "Dibayar di luar tabungan ({$tabel}): {$ket}",
                        'rekening_id' => $this->rekeningId['BSI'], 'tagihan_id' => null, 'legacy_ref' => "{$ref}:setor"],
                    $dasar + ['arah' => 'debit', 'jenis' => 'pembayaran_tagihan', 'keterangan' => "Bayar: {$ket}",
                        'rekening_id' => null, 'tagihan_id' => $idT, 'legacy_ref' => "{$ref}:bayar"],
                ]);
                $luar++;
                $this->masuk($tabel);
            }
            $this->sumber($tabel, count($rows));
            $this->catatan($tabel, "{$cocok} baris adalah salinan potongan tabungan (tidak digandakan), {$luar} baris dibayar di luar tabungan");
        }
    }

    private function pengeluaran(): void
    {
        $akun = DB::table('akun')->pluck('id', 'kode')->mapWithKeys(fn ($v, $k) => [strtolower($k) => $v])->all();
        $pengusul = DB::table('pengusul')->pluck('id', 'kode')->mapWithKeys(fn ($v, $k) => [strtolower($k) => $v])->all();
        $peta = ['tbl_pengeluaran' => 'SPP', 'tbl_pengeluaran_dsb' => 'DSB', 'tbl_pengeluaran_du' => 'DU',
            'tbl_pengeluaran_formulir' => 'FORMULIR', 'tbl_pengeluaran_pts' => 'PTS', 'tbl_pengeluaran_pas' => 'PAS'];
        foreach ($peta as $tabel => $dana) {
            $rows = $this->baca($tabel)->get();
            $isi = [];
            foreach ($rows as $r) {
                $isi[] = [
                    'tanggal' => $this->tgl($r->tanggal) ?? '2021-01-01', 'dana_id' => $this->danaId[$dana], 'rekening_id' => $this->rekeningId['BSI'],
                    'akun_id' => $akun[strtolower(trim((string) $r->kode_akun))] ?? null, 'pengusul_id' => $pengusul[strtolower(trim((string) $r->kode_pengusul))] ?? null,
                    'rutin' => strtoupper(trim((string) $r->rutin)) === 'RUTIN', 'nominal' => max(0, (int) $r->rp),
                    'keterangan' => mb_substr((string) $r->keterangan, 0, 255), 'dicatat_oleh' => $this->adminId,
                    'legacy_ref' => "{$tabel}:{$r->no}", 'created_at' => $this->now, 'updated_at' => $this->now,
                ];
            }
            foreach (array_chunk($isi, 500) as $c) {
                DB::table('pengeluaran')->insert($c);
            }
            $this->sumber($tabel, count($rows));
            $this->masuk($tabel, count($isi));
        }
    }

    private function mutasiBsi(): void
    {
        $rows = $this->baca('tbl_mutasi_bsi')->get();
        if ($rows->isEmpty()) {
            $this->sumber('tbl_mutasi_bsi', 0);

            return;
        }
        $impor = DB::table('bank_impor')->insertGetId(['rekening_id' => $this->rekeningId['BSI'], 'nama_file' => 'Migrasi tbl_mutasi_bsi',
            'jumlah_baris' => count($rows), 'diimpor_oleh' => $this->adminId, 'created_at' => $this->now, 'updated_at' => $this->now]);
        $sudah = [];
        foreach ($rows as $r) {
            $ref = trim((string) $r->no_refrensi);
            $kunci = $ref.'|'.(int) $r->debet.'|'.(int) $r->kredit; // FT sama bisa untuk transfer + biaya admin
            if ($ref === '' || isset($sudah[$kunci])) {
                $this->lewati('tbl_mutasi_bsi', 'no referensi kosong/ganda');

                continue;
            }
            $sudah[$kunci] = true;
            DB::table('bank_mutasi')->insert(['bank_impor_id' => $impor, 'rekening_id' => $this->rekeningId['BSI'], 'tanggal' => $this->tglJam($r->tanggal) ?? '2021-01-01 00:00:00',
                'no_referensi' => $ref, 'deskripsi' => $r->deskripsi, 'debet' => (int) $r->debet, 'kredit' => (int) $r->kredit, 'saldo' => (int) $r->saldo_rill,
                'status' => 'diabaikan', 'catatan' => 'Arsip dari aplikasi lama, tidak dicocokkan ulang', 'created_at' => $this->now, 'updated_at' => $this->now]);
            $this->masuk('tbl_mutasi_bsi');
        }
        $this->sumber('tbl_mutasi_bsi', count($rows));
    }

    private function raport(): void
    {
        // Kolom `image` berisi PDF asli (bisa beberapa MB per baris). Membaca 100 baris sekaligus membuat PHP
        // kehabisan memori (driver MySQL menampung seluruh hasil query di memori PHP). Maka: baca metadata
        // dulu tanpa file, lalu ambil file satu per satu hanya untuk raport yang benar-benar disalin.
        $meta = $this->lama->table('tbl_nilai_akhir')
            ->orderByDesc('id_nilai') // terbaru dulu: bila ganda untuk santri/semester/jenis yang sama, yang terbaru dipakai
            ->get(['id_nilai', 'id_siswa', 'id_periode', 'keterangan', 'mime']);
        $sudah = [];
        foreach ($meta->values() as $ke => $r) {
            $this->kemajuan($ke, $meta->count());
            $s = $this->santriMap[(int) $r->id_siswa] ?? null;
            $sem = $this->semByPeriode[(int) $r->id_periode] ?? null;
            $ket = strtoupper((string) $r->keterangan);
            $jenis = str_contains($ket, 'TENGAH') ? 'pts' : (str_contains($ket, 'TAHUN') ? 'pat' : (str_contains($ket, 'AKHIR') ? 'pas' : null));
            if (! $s || ! $sem || ! $jenis) {
                $this->lewati('tbl_nilai_akhir', 'santri/periode/jenis raport tidak dikenal');

                continue;
            }
            if (isset($sudah["{$s}|{$sem[0]}|{$jenis}"])) {
                $this->lewati('tbl_nilai_akhir', 'raport ganda (dipakai yang terbaru)');

                continue;
            }
            $sudah["{$s}|{$sem[0]}|{$jenis}"] = true;
            [$isi, $mime, $ext] = self::isiFileLama((string) $this->lama->table('tbl_nilai_akhir')->where('id_nilai', $r->id_nilai)->value('image'));
            $path = "raport/legacy/{$r->id_nilai}.{$ext}";
            ($this->simpanFile)($path, $isi);
            unset($isi);
            DB::table('raport')->insert(['santri_id' => $s, 'semester_id' => $sem[0], 'jenis' => $jenis, 'file_path' => $path,
                'mime' => $mime, 'diterbitkan_pada' => $this->now, 'diunggah_oleh' => $this->adminId,
                'legacy_id_nilai' => $r->id_nilai, 'created_at' => $this->now, 'updated_at' => $this->now]);
            $this->masuk('tbl_nilai_akhir');
        }
        $this->sumber('tbl_nilai_akhir', $meta->count());
    }

    /**
     * Foto santri: data_siswa.image (BLOB berisi teks base64) disalin menjadi FILE foto-santri/lama-<id_siswa>.jpg,
     * santri.foto menyimpan path-nya. Satu foto per query (lihat raport) dan diperkecil agar hemat memori & disk.
     * Nama file tetap per id lama, sehingga sinkron ulang menimpa file yang sama (tidak menumpuk).
     */
    private function fotoSantri(): void
    {
        $ada = 0;
        $ke = 0;
        foreach ($this->santriMap as $idLama => $santriId) {
            $this->kemajuan($ke++, count($this->santriMap));
            $mentah = $this->lama->table('data_siswa')->where('id_siswa', $idLama)->value('image');
            if ($mentah === null || trim((string) $mentah) === '') {
                continue;
            }
            $ada++;
            $foto = \App\Support\FotoSantri::dariLama((string) $mentah);
            unset($mentah);
            if (! $foto) {
                $this->lewati('data_siswa.image', 'isi kolom image bukan gambar JPG/PNG/GIF/WebP');

                continue;
            }
            $path = \App\Support\FotoSantri::FOLDER."/lama-{$idLama}.{$foto[1]}";
            ($this->simpanFile)($path, $foto[0]);
            unset($foto);
            DB::table('santri')->where('id', $santriId)->update(['foto' => $path]);
            $this->masuk('data_siswa.image');
        }
        $this->sumber('data_siswa.image', $ada);
    }

    /**
     * Aplikasi lama tidak membuat tagihan; bulan yang belum dipotong tidak tercatat di mana pun, sehingga tunggakan
     * tidak terlihat. Untuk tahun ajaran berjalan, tagihan bulanan (SPP, laundry, kesehatan) dibuat untuk setiap
     * bulan sampai bulan ini yang belum terisi potongan lama (lihat pilihPeriode). Saldo tidak disentuh: tagihan
     * ini berstatus belum dibayar dan baru terpotong saat ada setoran berikutnya.
     */
    private function tagihanBerjalan(): void
    {
        $ta = \App\Models\TahunAjaran::untukTanggal($this->now);
        $generator = app(\App\Services\TagihanGenerator::class);
        $n = 0;
        for ($bulan = CarbonImmutable::parse($ta->mulai)->startOfMonth(); $bulan->lte($this->now->startOfMonth()); $bulan = $bulan->addMonth()) {
            $n += $generator->bulanan($bulan);
        }
        $this->sumber('tagihan bulan berjalan', $n);
        $this->masuk('tagihan bulan berjalan', $n);
        $this->catatan('tagihan bulan berjalan', "{$n} tagihan bulanan belum dibayar dibuat untuk {$ta->nama} sampai {$this->now->locale('id')->translatedFormat('F Y')}");
    }

    /** tbl_kalender_akedemik (no, tanggal, kegiatan) -> kalender_akademik. */
    private function kalender(): void
    {
        if (! \Illuminate\Support\Facades\Schema::connection($this->lama->getName())->hasTable('tbl_kalender_akedemik')) {
            return;
        }
        $rows = $this->baca('tbl_kalender_akedemik')->orderBy('no')->get();
        foreach ($rows as $r) {
            $tgl = $this->tgl($r->tanggal);
            if (! $tgl || trim((string) $r->kegiatan) === '') {
                $this->lewati('tbl_kalender_akedemik', 'tanggal/kegiatan kosong');

                continue;
            }
            DB::table('kalender_akademik')->insert(['tanggal_mulai' => $tgl, 'kegiatan' => mb_substr(trim((string) $r->kegiatan), 0, 200),
                'legacy_no' => $r->no, 'dibuat_oleh' => $this->adminId, 'created_at' => $this->now, 'updated_at' => $this->now]);
            $this->masuk('tbl_kalender_akedemik');
        }
        $this->sumber('tbl_kalender_akedemik', $rows->count());
    }

    /** Saldo per santri: cara hitung aplikasi lama vs ledger baru. */
    private function rekonsiliasi(): array
    {
        $lama = $this->lama->table('tbl_tabungan_transaksi as t')
            ->join('tbl_tabungan_siswa as s', 's.kode_tab', '=', 't.kode_tab_trans')
            ->leftJoin('tbl_kode_transaksi as k', 'k.kode', '=', 't.kodetransaksi')
            ->groupBy('s.kode_siswa')
            ->selectRaw("s.kode_siswa AS id, SUM(CASE WHEN k.kode_tran='K' THEN t.rp WHEN k.kode_tran='D' THEN -t.rp ELSE 0 END) AS saldo")
            ->pluck('saldo', 'id')->all();
        $baru = DB::table('tabungan_mutasi')->where('status', 'terverifikasi')->groupBy('santri_id')
            ->selectRaw("santri_id, SUM(CASE WHEN arah='kredit' THEN nominal ELSE -nominal END) AS saldo")->pluck('saldo', 'santri_id')->all();

        $beda = [];
        $totLama = 0;
        $totBaru = 0;
        foreach ($this->santriMap as $idLama => $id) {
            $l = (int) ($lama[$idLama] ?? 0);
            $b = (int) ($baru[$id] ?? 0);
            $totLama += $l;
            $totBaru += $b;
            if ($l !== $b) {
                $beda[] = ['id_siswa_lama' => $idLama, 'santri_id' => $id, 'saldo_lama' => $l, 'saldo_baru' => $b, 'selisih' => $b - $l,
                    'penjelasan' => 'Perlu diperiksa'];
            }
        }

        return ['santri' => count($this->santriMap), 'sama' => count($this->santriMap) - count($beda), 'berbeda' => $beda,
            'total_saldo_lama' => $totLama, 'total_saldo_baru' => $totBaru];
    }

    // ------------------------------------------------------------------ bantuan

    private function muatReferensi(): void
    {
        $this->jenisId = DB::table('jenis_tagihan')->pluck('id', 'kode')->all();
        $this->jenisBulanan = DB::table('jenis_tagihan')->where('frekuensi', 'bulanan')->pluck('kode')->flip()->map(fn () => true)->all();
        $this->periodeTerpakai = [];
        $this->rekeningId = DB::table('rekening')->pluck('id', 'kode')->all();
        $this->danaId = DB::table('dana')->pluck('id', 'kode')->all();
        foreach (['SPP', 'LAUNDRY', 'KESEHATAN', 'INFAK', 'PTS', 'PAS', 'DU', 'DSB', 'BUKU', 'KEGIATAN'] as $k) {
            if (! isset($this->jenisId[$k])) {
                throw new AturanDilanggar("Jenis tagihan {$k} belum ada; jalankan MasterKeuanganSeeder dulu.");
            }
        }
    }

    /** @return array{0:string,1:?string,2:?string} [jenis mutasi, rekening, jenis tagihan] */
    private function petaKode(string $kode, string $ket): array
    {
        $k = strtolower($ket);

        return match ($kode) {
            'TKSPP', 'TKTAB', 'TKTRF' => ['setoran_transfer', 'BSI', null],
            'TKTNI' => ['setoran_tunai', 'KAS', null],
            'TDTNI' => ['penarikan_tunai', 'KAS', null],
            'TDPRE' => ['pengembalian', 'KAS', null],
            'TKKOR', 'TDKOR', 'TKPTS', 'TKPAS' => ['koreksi', null, null],
            'TDSPP' => ['pembayaran_tagihan', null, 'SPP'],
            'TDLDR' => ['pembayaran_tagihan', null, 'LAUNDRY'],
            'TDKES' => ['pembayaran_tagihan', null, 'KESEHATAN'],
            'TDINF' => ['pembayaran_tagihan', null, 'INFAK'],
            'TDPTS' => ['pembayaran_tagihan', null, 'PTS'],
            'TDPAS' => ['pembayaran_tagihan', null, 'PAS'],
            'TDADM' => ['pembayaran_tagihan', null, match (true) {
                str_contains($k, 'daftar ulang') || str_starts_with($k, 'du') || str_contains($k, ' du ') => 'DU',
                str_contains($k, 'buku') || str_contains($k, 'seragam') => 'BUKU',
                default => 'KEGIATAN',
            }],
            default => [str_starts_with($kode, 'TK') ? 'koreksi' : 'koreksi', null, null],
        };
    }

    private function barisTagihan(int $santri, string $jenis, string $tglJam, int $rp, string $ket, string $ref): array
    {
        $tgl = CarbonImmutable::parse($tglJam);
        $ta = $this->taId($tgl->month >= 7 ? $tgl->year : $tgl->year - 1);
        $nomor = $tgl->month >= 7 ? 1 : 2;

        $periode = isset($this->jenisBulanan[$jenis]) ? $this->pilihPeriode($santri, $jenis, $ta, $tgl) : null;

        return [
            'santri_id' => $santri, 'jenis_tagihan_id' => $this->jenisId[$jenis], 'tahun_ajaran_id' => $ta,
            'semester_id' => $this->semByTaNomor["{$ta}|{$nomor}"][0], 'periode' => $periode,
            'keterangan' => mb_substr($ket, 0, 200), 'nominal' => max(0, $rp), 'potongan' => 0, 'terbayar' => max(0, $rp),
            'jatuh_tempo' => $tgl->toDateString(), 'status' => 'lunas', 'legacy_ref' => $ref, 'dibuat_oleh' => $this->adminId,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ];
    }

    /**
     * Bulan yang dibayar oleh potongan bulanan lama (SPP, laundry, kesehatan). Aplikasi lama tidak menyimpan bulannya;
     * ia menghitung JUMLAH potongan per semester (Juli–Desember, Januari–Juni): 3 kali potong dalam satu semester
     * = 3 bulan lunas, entah dibayar di muka atau di akhir. Maka setiap potongan mengisi bulan kosong paling awal
     * di semester tanggal potong (tidak sebelum santri masuk); bila semester itu sudah penuh, bulan kosong
     * berikutnya di tahun ajaran yang sama. Lebih dari itu dibiarkan tanpa bulan.
     */
    private function pilihPeriode(int $santri, string $jenis, int $ta, CarbonImmutable $tgl): ?string
    {
        $mulaiTa = CarbonImmutable::create($tgl->month >= 7 ? $tgl->year : $tgl->year - 1, 7, 1);
        $awalSemester = $tgl->month >= 7 ? 0 : 6;
        $batasBawah = 0;
        if ($masuk = $this->masukSantri[$santri] ?? null) {
            $m = CarbonImmutable::parse($masuk)->startOfMonth();
            $batasBawah = max(0, min($awalSemester + 5, ($m->year - $mulaiTa->year) * 12 + $m->month - 7));
        }
        $kunci = "{$santri}|{$jenis}|{$ta}";
        for ($i = max($awalSemester, $batasBawah); $i <= 11; $i++) {
            if (! isset($this->periodeTerpakai[$kunci][$i])) {
                $this->periodeTerpakai[$kunci][$i] = true;

                return $mulaiTa->addMonths($i)->toDateString();
            }
        }

        return null;
    }

    /** @return array<string,int> legacy_ref => id */
    private function sisipkanTagihan(array $baris): array
    {
        if (! $baris) {
            return [];
        }
        foreach (array_chunk(array_values($baris), 500) as $c) {
            DB::table('tagihan')->insert($c);
        }

        return DB::table('tagihan')->whereIn('legacy_ref', array_keys($baris))->pluck('id', 'legacy_ref')->all();
    }

    private function ambilPasangan(int $santri, array $kode, ?int $rp, string $tgl): ?int
    {
        foreach ($kode as $k) {
            $daftar = $rp !== null ? ($this->debitIndex["{$santri}|{$k}|{$rp}|{$tgl}"] ?? []) : ($this->debitIndexTgl["{$santri}|{$k}|{$tgl}"] ?? []);
            foreach ($daftar as $id) {
                if (! isset($this->dipakai[$id])) {
                    $this->dipakai[$id] = true;

                    return $id;
                }
            }
        }

        return null;
    }

    private function taId(int $tahun): int
    {
        if (isset($this->taByTahun[$tahun])) {
            return $this->taByTahun[$tahun];
        }
        $id = DB::table('tahun_ajaran')->insertGetId(['nama' => $tahun.'/'.($tahun + 1), 'tahun_mulai' => $tahun,
            'mulai' => "{$tahun}-07-01", 'selesai' => ($tahun + 1).'-06-30', 'aktif' => false, 'created_at' => $this->now, 'updated_at' => $this->now]);
        foreach ([1 => ["{$tahun}-07-01", "{$tahun}-12-31"], 2 => [($tahun + 1).'-01-01', ($tahun + 1).'-06-30']] as $n => [$a, $b]) {
            $sem = DB::table('semester')->insertGetId(['tahun_ajaran_id' => $id, 'nomor' => $n, 'nama' => Semester::namaBaku($n, $tahun.'/'.($tahun + 1)),
                'mulai' => $a, 'selesai' => $b, 'aktif' => false, 'created_at' => $this->now, 'updated_at' => $this->now]);
            $this->semByTaNomor["{$id}|{$n}"] = [$sem, $a];
        }

        return $this->taByTahun[$tahun] = $id;
    }

    private function tgl(mixed $v): ?string
    {
        $v = trim((string) $v);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || (int) $m[1] < 1900 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    private function tglJam(mixed $v): ?string
    {
        $d = $this->tgl($v);
        if (! $d) {
            return null;
        }
        $jam = preg_match('/(\d{2}:\d{2}:\d{2})/', (string) $v, $m) ? $m[1] : '00:00:00';

        return "{$d} {$jam}";
    }

    private function kosongNull(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private function sumber(string $t, int $n): void
    {
        $this->r($t)['sumber'] = $n;
    }

    private function masuk(string $t, int $n = 1): void
    {
        $this->r($t)['masuk'] += $n;
    }

    private function lewati(string $t, string $alasan): void
    {
        $this->r($t)['dilewati']++;
        $this->catatan($t, 'Dilewati: '.$alasan);
    }

    private function catatan(string $t, string $c): void
    {
        $r = &$this->r($t);
        if (! in_array($c, $r['catatan'], true)) {
            $r['catatan'][] = $c;
        }
    }

    private function &r(string $t): array
    {
        $this->ringkasan[$t] ??= ['sumber' => 0, 'masuk' => 0, 'dilewati' => 0, 'catatan' => []];

        return $this->ringkasan[$t];
    }
}
