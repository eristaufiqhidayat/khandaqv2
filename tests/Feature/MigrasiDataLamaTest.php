<?php

namespace Tests\Feature;

use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Models\Santri;
use App\Models\TabunganMutasi;
use App\Models\TutupBuku;
use App\Services\Migrasi\MigrasiDataLama;
use Tests\KhandaqTestCase;

/**
 * Uji terhadap salinan database lama yang sudah dianonimkan (dimuat ke SQLite).
 * Dilewati bila berkasnya tidak ada.
 */
class MigrasiDataLamaTest extends KhandaqTestCase
{
    private const DB_LAMA = '/home/claude/work/sino.db';

    protected function setUp(): void
    {
        parent::setUp();
        $path = getenv('KHANDAQ_DB_LAMA_UJI') ?: self::DB_LAMA;
        if (! is_file($path)) {
            $this->markTestSkipped('Salinan database lama (SQLite) tidak tersedia. Isi KHANDAQ_DB_LAMA_UJI untuk menjalankan tes ini.');
        }
        if (function_exists('lab_koneksi_lama')) {
            lab_koneksi_lama($path);
        } else {
            config(['database.connections.lama' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false]]);
            \Illuminate\Support\Facades\DB::purge('lama');
        }
    }

    private function jalankan(?string $loginLama = null): array
    {
        $file = [];
        $svc = new MigrasiDataLama('lama', 'paralel', function ($path, $isi) use (&$file) { $file[$path] = strlen($isi); }, $loginLama);
        $admin = $this->beriIzin($this->buatUser('Admin'), Izin::MigrasiJalankan);

        return [$svc->jalankan($admin), $file, $admin];
    }

    public function test_salin_semua_data_lama_dan_saldo_sama_per_santri(): void
    {
        [$run, $file] = $this->jalankan();
        $r = $run->ringkasan;
        $rek = $run->rekonsiliasi;
        fwrite(STDERR, "\n".json_encode(['ringkasan' => $r, 'rekonsiliasi' => array_merge($rek, ['berbeda' => array_slice($rek['berbeda'], 0, 5)])], JSON_PRETTY_PRINT)."\n");

        $this->assertSame('selesai', $run->status);
        $this->assertSame(353, Santri::count());
        $this->assertSame(94461, $r['tbl_tabungan_transaksi']['sumber']);
        foreach ($r as $tabel => $x) {
            $this->assertSame($x['sumber'], $x['masuk'] + $x['dilewati'], "{$tabel}: setiap baris sumber masuk atau tercatat dilewati");
        }
        $this->assertSame(94457, $r['tbl_tabungan_transaksi']['masuk'], '4 baris tanpa buku tabungan/santri dilewati');
        $this->assertSame(94457, TabunganMutasi::whereNotNull('legacy_notransaksi')->count());

        $this->assertSame(353, $rek['santri']);
        $this->assertSame([], $rek['berbeda'], 'saldo setiap santri sama persis dengan cara hitung aplikasi lama');
        $this->assertSame($rek['total_saldo_lama'], $rek['total_saldo_baru']);
        $this->assertSame(4, TabunganMutasi::where('legacy_kode', 'TDKOR')->where('status', 'ditolak')->count(), 'TDKOR disalin, tidak dihitung');
        $this->assertGreaterThan(1500, count($file), 'raport disalin menjadi file');

        // Potongan SPP/laundry/kesehatan lama diberi bulan (periode) agar tampil di kisi Status pembayaran.
        $spp = \App\Models\JenisTagihan::kode('SPP');
        $tanpaBulan = \App\Models\Tagihan::where('jenis_tagihan_id', $spp->id)->whereNull('periode')->count();
        $total = \App\Models\Tagihan::where('jenis_tagihan_id', $spp->id)->count();
        fwrite(STDERR, "SPP lama: {$total} tagihan, tanpa bulan {$tanpaBulan}\n");
        $this->assertGreaterThan(1000, $total);
        $this->assertLessThan($total * 0.03, $tanpaBulan, 'hampir semua potongan SPP mendapat bulan');
        $baris = (new \App\Services\LaporanTagihan())->rekapSpp(\App\Models\TahunAjaran::untukTanggal(\Carbon\CarbonImmutable::create(2025, 7, 1)), \Carbon\CarbonImmutable::create(2026, 6, 30));
        $lunas = collect($baris)->sum(fn ($b) => collect($b['bulan'])->filter(fn ($v) => $v === \App\Services\LaporanTagihan::LUNAS)->count());
        $this->assertGreaterThan(500, $lunas, 'kisi SPP 2025/2026 terisi dari data lama');
    }

    public function test_password_wali_dari_database_login_lama_ikut_terbawa(): void
    {
        // Tiruan lembaha1_igni399.users (Myth/Auth). Username wali = data_orangtua.username.
        $ids = (new \PDO('sqlite:'.(getenv('KHANDAQ_DB_LAMA_UJI') ?: self::DB_LAMA)))->query('SELECT id_orangtua, username FROM data_orangtua ORDER BY id_orangtua LIMIT 3')->fetchAll(\PDO::FETCH_KEY_PAIR);
        [$id1, $id2, $id3] = array_keys($ids);
        $myth = fn (string $pw) => password_hash(base64_encode(hash('sha384', $pw, true)), PASSWORD_DEFAULT);
        $path = sys_get_temp_dir().'/login_lama_'.uniqid().'.db';
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, active INT, ortu TEXT, deleted_at TEXT)');
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, active, ortu, deleted_at) VALUES (?,?,?,?,?)');
        $ins->execute([strtoupper($ids[$id1]), $myth('rahasia123'), 1, '1', null]); // beda huruf besar/kecil tetap cocok
        $ins->execute([$ids[$id2], $myth('lama456'), 0, '1', null]);                // akun nonaktif: tidak dibawa
        $ins->execute([$ids[$id3], $myth('staf789'), 1, null, null]);              // bukan akun orang tua
        $ins->execute(['wali_tanpa_data', $myth('x'), 1, '1', null]);
        if (isset($GLOBALS['lab_capsule'])) {
            $GLOBALS['lab_capsule']->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => ''], 'login_lama');
        } else {
            config(['database.connections.login_lama' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
            \Illuminate\Support\Facades\DB::purge('login_lama');
        }

        [$run] = $this->jalankan('login_lama');

        $u1 = \App\Models\User::where('legacy_id_orangtua', $id1)->firstOrFail();
        $this->assertNotNull($u1->password_lama);
        $this->assertFalse((bool) $u1->wajib_ganti_password);
        $this->assertTrue((new \App\Services\AkunService())->cocokkanPassword($u1, 'rahasia123'), 'wali masuk dengan password lamanya');
        foreach ([$id2, $id3] as $id) {
            $u = \App\Models\User::where('legacy_id_orangtua', $id)->firstOrFail();
            $this->assertNull($u->password_lama);
            $this->assertTrue((bool) $u->wajib_ganti_password);
        }
        $this->assertContains('1 wali membawa password dari aplikasi lama; '.(Santri::query()->getConnection()->table('users')->whereNotNull('legacy_id_orangtua')->count() - 1).' wali tanpa akun lama (password sementara saat go-live)', $run->ringkasan['data_orangtua']['catatan']);
        @unlink($path);
    }

    public function test_dijalankan_ulang_mengganti_bukan_menggandakan(): void
    {
        $this->jalankan();
        $n1 = [Santri::count(), TabunganMutasi::count(), \App\Models\Tagihan::count()];
        $this->jalankan();
        $this->assertSame($n1, [Santri::count(), TabunganMutasi::count(), \App\Models\Tagihan::count()]);
    }

    public function test_terkunci_setelah_tutup_buku(): void
    {
        [, , $admin] = $this->jalankan();
        TutupBuku::create(['bulan' => '2026-05-01', 'saldo_titipan' => 0, 'ditutup_oleh' => $admin->id]);
        $this->expectException(AturanDilanggar::class);
        $this->jalankan();
    }

    public function test_foto_santri_blob_base64_disalin_menjadi_file(): void
    {
        $asli = getenv('KHANDAQ_DB_LAMA_UJI') ?: self::DB_LAMA;
        $salinan = sys_get_temp_dir().'/sino_foto_'.uniqid().'.db';
        copy($asli, $salinan);
        $img = imagecreatetruecolor(1200, 900);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $pdo = new \PDO('sqlite:'.$salinan);
        [$a, $b] = $pdo->query('SELECT id_siswa FROM data_siswa ORDER BY id_siswa LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->prepare('UPDATE data_siswa SET image = ? WHERE CAST(id_siswa AS TEXT) = ?')->execute([base64_encode($png), $a]); // seperti C_DataSiswa lama
        $pdo->prepare('UPDATE data_siswa SET image = NULL WHERE CAST(id_siswa AS TEXT) = ?')->execute([$b]);
        config(['database.connections.lama.database' => $salinan]);
        \Illuminate\Support\Facades\DB::purge('lama');

        try {
            [$run, $file] = $this->jalankan();
        } finally {
            @unlink($salinan);
        }
        $foto = Santri::where('legacy_id_siswa', $a)->value('foto');
        $this->assertSame("foto-santri/lama-{$a}.jpg", $foto);
        $this->assertArrayHasKey($foto, $file);
        $this->assertGreaterThan(0, $file[$foto]);
        $this->assertNull(Santri::where('legacy_id_siswa', $b)->value('foto'));
        $r = $run->ringkasan['data_siswa.image'];
        $this->assertSame(1, $r['masuk']);
        $this->assertSame($r['sumber'], $r['masuk'] + $r['dilewati'], 'isi bukan gambar (mis. data uji "ADA") dicatat dilewati');
    }

    public function test_potongan_bulanan_dihitung_per_semester_dari_awal_semester(): void
    {
        $svc = new MigrasiDataLama('lama', 'paralel');
        $pilih = new \ReflectionMethod($svc, 'pilihPeriode');
        $ta = 1;
        $tgl = fn (string $d) => \Carbon\CarbonImmutable::parse($d);
        // Dibayar di akhir semester: 3 potongan Desember = Juli, Agustus, September.
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01'],
            array_map(fn ($d) => $pilih->invoke($svc, 10, 'SPP', $ta, $tgl($d)), ['2026-12-05', '2026-12-05', '2026-12-20']));
        // Dibayar di muka: potongan Juli 3 kali untuk santri lain = Juli–September juga.
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01'],
            array_map(fn ($d) => $pilih->invoke($svc, 11, 'SPP', $ta, $tgl($d)), ['2026-07-02', '2026-07-02', '2026-07-02']));
        // Semester genap mulai Januari; tiap jenis dihitung sendiri.
        $this->assertSame('2027-01-01', $pilih->invoke($svc, 10, 'SPP', $ta, $tgl('2027-03-01')));
        $this->assertSame('2026-07-01', $pilih->invoke($svc, 10, 'LAUNDRY', $ta, $tgl('2026-11-01')));
    }

    public function test_bulan_belum_dipotong_menjadi_tunggakan_tahun_berjalan(): void
    {
        \Carbon\CarbonImmutable::setTestNow('2026-06-15 10:00');
        try {
            [$run] = $this->jalankan();
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
        $this->assertSame([], $run->rekonsiliasi['berbeda'], 'saldo tetap sama: tagihan baru belum dibayar, tidak memotong saldo');
        $this->assertGreaterThan(0, $run->ringkasan['tagihan bulan berjalan']['masuk']);
        $ta = \App\Models\TahunAjaran::untukTanggal(\Carbon\CarbonImmutable::create(2025, 7, 1));
        $baris = (new \App\Services\LaporanTagihan())->rekapSpp($ta, \Carbon\CarbonImmutable::create(2026, 6, 15));
        $menunggak = collect($baris)->filter(fn ($b) => $b['bulan_terlambat'] > 0);
        fwrite(STDERR, "Menunggak SPP 2025/2026 per 15 Jun 2026: {$menunggak->count()} santri, Rp".number_format($menunggak->sum('sisa_terlambat'), 0, ',', '.')."\n");
        $this->assertGreaterThan(0, $menunggak->count());
        // Santri tidak ditagih untuk bulan sebelum ia masuk.
        foreach (\App\Models\Tagihan::whereNull('legacy_ref')->with('santri')->get() as $t) {
            $this->assertTrue(! $t->santri->tanggal_masuk || $t->periode->endOfMonth()->gte($t->santri->tanggal_masuk), "tagihan {$t->id} sebelum santri masuk");
        }
    }
}
