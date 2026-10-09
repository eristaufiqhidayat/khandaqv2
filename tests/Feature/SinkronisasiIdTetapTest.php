<?php

namespace Tests\Feature;

use App\Enums\Izin;
use App\Models\CatatanHarianGuru;
use App\Models\GuruMengajar;
use App\Models\Mapel;
use App\Models\Nilai;
use App\Models\Santri;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Migrasi\MigrasiDataLama;
use Illuminate\Support\Facades\DB;
use Tests\KhandaqTestCase;

/**
 * Sinkronisasi data lama dengan id tetap: santri.id = id_siswa, kelas.id = id_kelas,
 * dan nilai/penugasan guru bertahan saat sinkron diulang. Memakai database lama mini (SQLite sementara).
 */
class SinkronisasiIdTetapTest extends KhandaqTestCase
{
    private string $berkas;

    /** Tabel lama yang dibaca sinkronisasi. Kolomnya digabung: cukup untuk semua tahap. */
    private const TABEL = ['setup_periode', 'setup_kelas', 'data_siswa', 'data_orangtua', 'tbl_akses_ortu', 'tbl_ruangan', 'tbl_ruangan_periode',
        'tbl_akun', 'tbl_pengusul', 'tbl_tarif_spp', 'tbl_beasiswa', 'tbl_kode_transaksi', 'tbl_tabungan_siswa', 'tbl_tabungan_transaksi',
        'tbl_pts', 'tbl_pas', 'tbl_iuran_loundry', 'tbl_daftar_ulang2', 'tbl_uang_buku', 'tbl_dsb',
        'tbl_pengeluaran', 'tbl_pengeluaran_dsb', 'tbl_pengeluaran_du', 'tbl_pengeluaran_formulir', 'tbl_pengeluaran_pts', 'tbl_pengeluaran_pas',
        'tbl_mutasi_bsi', 'tbl_nilai_akhir', 'tbl_kalender_akedemik'];

    private const KOLOM = ['no', 'id_periode', 'tahun_ajaran', 'semester', 'status_aktif', 'id_kelas', 'nama_kelas', 'id_siswa', 'nis', 'nisn', 'unik',
        'locked', 'nama_siswa', 'kelamin', 'tempat_lahir', 'tanggal_lahir', 'alamat_siswa', 'telpon_siswa', 'tanggal_masuk', 'keterangan', 'image',
        'id_orangtua', 'nama_orangtua', 'telpon_orangtua', 'email', 'alamat_orangtua', 'pekerjaan', 'status_keluarga', 'username',
        'kode_akun', 'nama_akun', 'kode_pengusul', 'nama_pengusul', 'periode', 'tarif', 'id_beasiswa', 'peserta', 'rp', 'persen',
        'kode', 'kode_tran', 'kode_tab', 'kode_siswa', 'notransaksi', 'kode_tab_trans', 'tanggal', 'kodetransaksi', 'keterangan_trans', 'buktitransfer',
        'jenis', 'rutin', 'no_refrensi', 'deskripsi', 'debet', 'kredit', 'saldo_rill', 'id_nilai', 'mime', 'kegiatan'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->berkas = tempnam(sys_get_temp_dir(), 'lama').'.sqlite';
        touch($this->berkas);
        config(['database.connections.lama' => ['driver' => 'sqlite', 'database' => $this->berkas, 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('lama');
        $lama = DB::connection('lama');
        foreach (self::TABEL as $t) {
            $lama->statement("CREATE TABLE {$t} (".implode(', ', array_map(fn ($k) => "{$k} TEXT", self::KOLOM)).')');
        }
        $lama->table('setup_periode')->insert(['id_periode' => 15, 'tahun_ajaran' => '2026/2027', 'semester' => 'ganjil', 'status_aktif' => 'yes']);
        $lama->table('setup_kelas')->insert([['id_kelas' => 7, 'nama_kelas' => '4A'], ['id_kelas' => 9, 'nama_kelas' => '5A']]);
        $lama->table('data_siswa')->insert([
            ['id_siswa' => 512, 'nis' => '240401', 'nama_siswa' => 'Ahmad Fauzi', 'kelamin' => 'laki-laki', 'locked' => 'no', 'unik' => '512'],
            ['id_siswa' => 513, 'nis' => '240402', 'nama_siswa' => 'Siti Rahma', 'kelamin' => 'perempuan', 'locked' => 'no', 'unik' => '513'],
        ]);
        $lama->table('tbl_ruangan')->insert([['id_siswa' => 512, 'id_kelas' => 7], ['id_siswa' => 513, 'id_kelas' => 7]]);
    }

    protected function tearDown(): void
    {
        DB::purge('lama');
        @unlink($this->berkas);
        parent::tearDown();
    }

    private function sinkron(User $admin): \App\Models\MigrasiRun
    {
        return (new MigrasiDataLama('lama', 'paralel', fn () => null))->jalankan($admin);
    }

    public function test_id_santri_dan_kelas_sama_dengan_data_lama_dan_nilai_guru_bertahan_saat_sinkron_ulang(): void
    {
        $admin = $this->beriIzin($this->buatUser('Admin'), Izin::MigrasiJalankan);
        $run = $this->sinkron($admin);
        $this->assertSame('selesai', $run->status, (string) $run->galat);
        $this->assertSame([512, 513], Santri::orderBy('id')->pluck('id')->all(), 'santri.id = id_siswa');
        $this->assertSame([7, 9], DB::table('kelas')->orderBy('id')->pluck('id')->all(), 'kelas.id = id_kelas');

        // Guru mengisi nilai, penugasan, dan catatan harian di aplikasi baru.
        $ta = TahunAjaran::where('tahun_mulai', 2026)->sole();
        $sem = Semester::where('tahun_ajaran_id', $ta->id)->where('nomor', 1)->sole();
        $guru = tap($this->buatUser('Guru'), fn ($u) => $u->update(['wajib_ganti_password' => false]))->assignRole('guru');
        $mapel = Mapel::create(['nama' => 'Matematika', 'kkm' => 75]);
        $tugas = GuruMengajar::create(['user_id' => $guru->id, 'kelas_id' => 7, 'mapel_id' => $mapel->id, 'tahun_ajaran_id' => $ta->id]);
        $n1 = Nilai::create(['santri_id' => 512, 'mapel_id' => $mapel->id, 'semester_id' => $sem->id, 'kelas_id' => 7, 'harian_1' => 88, 'uts' => 80, 'catatan_uts' => 'baik', 'diubah_oleh' => $guru->id]);
        Nilai::create(['santri_id' => 513, 'mapel_id' => $mapel->id, 'semester_id' => $sem->id, 'kelas_id' => 7, 'harian_1' => 70]);
        $catatan = CatatanHarianGuru::create(['user_id' => $guru->id, 'tanggal' => '2026-10-01', 'kelas_id' => 7, 'kelas_nama' => '4A', 'mapel_id' => $mapel->id, 'materi' => 'Pecahan']);

        // Santri 513 dihapus di aplikasi lama, lalu sinkron diulang.
        DB::connection('lama')->table('data_siswa')->where('id_siswa', 513)->delete();
        DB::connection('lama')->table('tbl_ruangan')->where('id_siswa', 513)->delete();
        $run = $this->sinkron($admin);
        $this->assertSame('selesai', $run->status, (string) $run->galat);

        $this->assertSame([512], Santri::pluck('id')->all());
        $semBaru = Semester::whereHas('tahunAjaran', fn ($q) => $q->where('tahun_mulai', 2026))->where('nomor', 1)->sole();
        $this->assertNotSame($sem->id, $semBaru->id, 'semester memang dibuat ulang');

        $n = Nilai::sole();
        $this->assertSame([$n1->id, 512, 7, $semBaru->id, 88, 80, 'baik', $guru->id],
            [$n->id, $n->santri_id, $n->kelas_id, $n->semester_id, $n->harian_1, $n->uts, $n->catatan_uts, $n->diubah_oleh], 'nilai utuh, semester dipasang ulang');
        $t = GuruMengajar::sole();
        $this->assertSame([$tugas->id, $guru->id, 7, $semBaru->tahun_ajaran_id], [$t->id, $t->user_id, $t->kelas_id, $t->tahun_ajaran_id]);
        $this->assertSame(7, $catatan->fresh()->kelas_id, 'kelas catatan harian tersambung lagi');

        $r = $run->ringkasan;
        $this->assertSame(['sumber' => 2, 'masuk' => 1, 'dilewati' => 1], array_intersect_key($r['nilai (modul guru)'], array_flip(['sumber', 'masuk', 'dilewati'])));
        $this->assertSame(1, $r['guru_mengajar']['masuk']);

        // Guru melihat nilainya lagi di layar Input nilai.
        $this->actingAs($guru)->get(route('guru.nilai.index', ['ta' => $semBaru->tahun_ajaran_id, 'semester' => $semBaru->id, 'kelas' => 7, 'mapel' => $mapel->id]))
            ->assertOk()->assertSee('Ahmad Fauzi')->assertSee('value="88"', false);

        // Santri yang didaftarkan di aplikasi baru mendapat id setelah id_siswa terbesar.
        $baru = Santri::create(['nis' => 'BARU1', 'nama' => 'Santri Baru', 'jenis_kelamin' => 'laki-laki', 'status' => 'calon']);
        $this->assertGreaterThan(512, $baru->id);
    }

    /**
     * Sinkron pertama setelah id tetap diberlakukan: data di server masih ber-id lama hasil sinkron sebelumnya
     * (santri/kelas dengan id otomatis). Nilai & penugasan harus tetap terpasang ke santri/kelas yang benar.
     */
    public function test_nilai_dan_penugasan_tetap_ada_saat_sinkron_pertama_dari_id_lama(): void
    {
        // Keadaan server sebelum PR id tetap: id otomatis, berbeda dengan id_siswa / id_kelas lama.
        $ta = TahunAjaran::untukTanggal(\Carbon\CarbonImmutable::create(2026, 7, 1));
        $sem = $ta->semester()->where('nomor', 1)->sole();
        $kelasId = DB::table('kelas')->insertGetId(['id' => 37, 'nama' => '4A', 'tingkat' => 4, 'aktif' => true, 'legacy_id_kelas' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $ahmad = Santri::create(['id' => 1840, 'legacy_id_siswa' => 512, 'nis' => '240401', 'nama' => 'Ahmad Fauzi', 'jenis_kelamin' => 'laki-laki', 'status' => 'aktif']);
        // Santri lain yang id otomatisnya kebetulan 512 (= id_siswa Ahmad): nilainya TIDAK boleh berpindah ke Ahmad.
        $siti = Santri::create(['id' => 512, 'legacy_id_siswa' => 513, 'nis' => '240402', 'nama' => 'Siti Rahma', 'jenis_kelamin' => 'perempuan', 'status' => 'aktif']);
        $guru = tap($this->buatUser('Guru'), fn ($u) => $u->update(['wajib_ganti_password' => false]))->assignRole('guru');
        $mapel = Mapel::create(['nama' => 'Matematika', 'kkm' => 75]);
        GuruMengajar::create(['user_id' => $guru->id, 'kelas_id' => $kelasId, 'mapel_id' => $mapel->id, 'tahun_ajaran_id' => $ta->id]);
        Nilai::create(['santri_id' => $ahmad->id, 'mapel_id' => $mapel->id, 'semester_id' => $sem->id, 'kelas_id' => $kelasId, 'harian_1' => 91, 'uas' => 85]);
        Nilai::create(['santri_id' => $siti->id, 'mapel_id' => $mapel->id, 'semester_id' => $sem->id, 'kelas_id' => $kelasId, 'harian_1' => 64]);
        $catatan = CatatanHarianGuru::create(['user_id' => $guru->id, 'tanggal' => '2026-10-01', 'kelas_id' => $kelasId, 'kelas_nama' => '4A', 'mapel_id' => $mapel->id, 'materi' => 'Pecahan']);

        $run = $this->sinkron($this->beriIzin($this->buatUser('Admin'), Izin::MigrasiJalankan));
        $this->assertSame('selesai', $run->status, (string) $run->galat);

        $this->assertSame([512, 513], Santri::orderBy('id')->pluck('id')->all());
        $this->assertSame(['harian_1' => 91, 'uas' => 85], Nilai::where('santri_id', 512)->sole()->only(['harian_1', 'uas']), 'nilai Ahmad (id_siswa 512)');
        $this->assertSame(64, Nilai::where('santri_id', 513)->sole()->harian_1, 'nilai Siti (id_siswa 513), bukan tertukar');
        $this->assertSame([7], Nilai::pluck('kelas_id')->unique()->values()->all());
        $t = GuruMengajar::sole();
        $this->assertSame([$guru->id, 7, $mapel->id], [$t->user_id, $t->kelas_id, $t->mapel_id]);
        $this->assertSame(TahunAjaran::where('tahun_mulai', 2026)->value('id'), $t->tahun_ajaran_id);
        $this->assertSame(7, $catatan->fresh()->kelas_id);
        $this->assertSame(0, $run->ringkasan['nilai (modul guru)']['dilewati']);
        $this->assertSame(1, $run->ringkasan['guru_mengajar']['masuk']);
    }
}
