<?php

namespace Tests\Feature;

use App\Models\CatatanHarianGuru;
use App\Models\GuruMengajar;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Nilai;
use App\Models\SoalPg;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\AkunService;
use Carbon\CarbonImmutable;
use Tests\KhandaqTestCase;

class ModulGuruTest extends KhandaqTestCase
{
    private TahunAjaran $ta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ta = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1));
        $this->ta->update(['aktif' => true]);
        $this->ta->semester()->where('nomor', 1)->update(['aktif' => true]);
    }

    private function akun(string $username, string $peran): User
    {
        return User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@test.local",
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole($peran);
    }

    private function semester(): int
    {
        return $this->ta->semester()->where('nomor', 1)->value('id');
    }

    /** @return array{0: User, 1: Mapel, 2: Kelas} guru yang mengajar Matematika di 3 PUTRA */
    private function siapkan(): array
    {
        $guru = $this->akun('guru1', 'guru');
        $mapel = Mapel::create(['nama' => 'Matematika', 'kode' => 'MTK', 'kkm' => 75]);
        $kelas = Kelas::where('nama', '3 PUTRA')->firstOrFail();
        GuruMengajar::create(['user_id' => $guru->id, 'kelas_id' => $kelas->id, 'mapel_id' => $mapel->id, 'tahun_ajaran_id' => $this->ta->id]);

        return [$guru, $mapel, $kelas];
    }

    public function test_peran_guru_dari_migrasi_masuk_ke_dashboard_guru_dan_hanya_melihat_menu_guru(): void
    {
        $this->assertTrue(\Spatie\Permission\Models\Role::findByName('guru')->hasPermissionTo('guru.mengajar'));
        $this->assertTrue(\Spatie\Permission\Models\Role::findByName('admin')->hasPermissionTo('mapel.kelola'));
        $this->akun('guru1', 'guru');

        $this->post('/masuk', ['username' => 'guru1', 'password' => 'rahasia123']);
        $this->get('/beranda')->assertRedirect(route('guru.dashboard'));
        $html = $this->get(route('guru.dashboard'))->assertOk()->assertSee('Belum ada kelas')->getContent();
        foreach (['Dashboard guru', 'Catatan harian guru', 'Soal pilihan ganda', 'Input nilai siswa', 'Rekap nilai', 'Menu guru'] as $menu) {
            $this->assertStringContainsString($menu, $html);
        }
        $this->assertStringNotContainsString('>Kasir santri<', $html);
        $this->get(route('kasir.index'))->assertForbidden();
        $this->get(route('mapel.index'))->assertForbidden();
    }

    public function test_admin_membuat_mapel_dan_menugaskan_guru_ke_beberapa_kelas(): void
    {
        $guru = $this->akun('guru1', 'guru');
        $this->actingAs($this->akun('admin', 'admin'));
        $this->post(route('mapel.store'), ['nama' => 'IPA', 'kkm' => 70])->assertSessionHas('status');
        $ipa = Mapel::where('nama', 'IPA')->sole();
        $kelas = Kelas::whereIn('nama', ['3 PUTRA', '4'])->pluck('id')->all();

        $this->post(route('mapel.tugaskan'), ['tahun_ajaran_id' => $this->ta->id, 'user_id' => $guru->id, 'mapel_id' => $ipa->id, 'kelas_id' => $kelas])->assertSessionHas('status');
        $this->post(route('mapel.tugaskan'), ['tahun_ajaran_id' => $this->ta->id, 'user_id' => $guru->id, 'mapel_id' => $ipa->id, 'kelas_id' => $kelas]);
        $this->assertSame(count($kelas), GuruMengajar::count(), 'tidak ganda');
        $this->get(route('mapel.index'))->assertOk()->assertSee('3 PUTRA · IPA');

        // Bukan guru: ditolak.
        $this->post(route('mapel.tugaskan'), ['tahun_ajaran_id' => $this->ta->id, 'user_id' => auth()->id(), 'mapel_id' => $ipa->id, 'kelas_id' => $kelas])->assertSessionHasErrors('user_id');
        $this->get(route('pengguna.index'))->assertOk()->assertSee('Guru');
    }

    public function test_guru_input_nilai_harian_uts_uas_dan_rekap_menghitung_nilai_akhir(): void
    {
        [$guru, $mapel, $kelas] = $this->siapkan();
        $a = $this->santri('3 PUTRA');
        $b = $this->santri('3 PUTRA');
        $lain = $this->santri('4');
        $q = ['ta' => $this->ta->id, 'semester' => $this->semester(), 'kelas' => $kelas->id, 'mapel' => $mapel->id];
        $this->actingAs($guru);

        $this->get(route('guru.nilai.index', $q))->assertOk()->assertSee($a->nama)->assertSee($b->nama)->assertDontSee($lain->nama)->assertSee('Harian 1');

        $this->post(route('guru.nilai.simpan'), $q + ['tab' => 'harian', 'nilai' => [
            $a->id => ['harian_1' => 80, 'harian_2' => 90, 'harian_3' => '', 'tugas' => 100, 'catatan' => 'rajin'],
            $b->id => ['harian_1' => 60, 'harian_2' => 70],
            $lain->id => ['harian_1' => 100], // bukan santri kelas ini: diabaikan
        ]])->assertSessionHas('status');
        $this->post(route('guru.nilai.simpan'), $q + ['tab' => 'uts', 'nilai' => [$a->id => ['uts' => 80], $b->id => ['uts' => 60]]]);
        $this->post(route('guru.nilai.simpan'), $q + ['tab' => 'uas', 'nilai' => [$a->id => ['uas' => 70]]]);

        $na = Nilai::where('santri_id', $a->id)->sole();
        $this->assertSame([80, 90, null, 100, 80, 70, 'rajin'], [$na->harian_1, $na->harian_2, $na->harian_3, $na->tugas, $na->uts, $na->uas, $na->catatan_harian]);
        $this->assertSame(90.0, $na->rataHarian());
        $this->assertSame(82.5, $na->nilaiAkhir(), '50% x 90 + 25% x 80 + 25% x 70');
        $this->assertSame(63.3, Nilai::where('santri_id', $b->id)->sole()->nilaiAkhir(), 'UAS kosong: bobot harian 50 & UTS 25 dibagi ulang');
        $this->assertFalse(Nilai::where('santri_id', $lain->id)->exists());
        $this->assertSame('nilai', \Spatie\Activitylog\Models\Activity::where('subject_type', Nilai::class)->value('log_name'));

        $this->get(route('guru.rekap.index', $q))->assertOk()->assertSee('82,5')->assertSee('Belum tuntas')->assertSee('Tuntas');
        $this->get(route('guru.rekap.index', $q + ['mode' => 'kelas']))->assertOk()->assertSee('MTK');
        $csv = $this->get(route('guru.rekap.unduh', $q))->assertOk()->streamedContent();
        $this->assertStringContainsString('"'.$a->nama.'";80;90;;100;90;80;70;82,5;B;Ya;1', $csv);
        $this->assertStringContainsString('"'.$b->nama.'";60;70;;;65;60;;63,3;D;Belum;2', $csv);
        $this->get(route('guru.dashboard', $q))->assertOk()->assertSee('Harian 2/2')->assertSee('UAS 1/2');

        // Nilai di luar 0..100 ditolak, tidak tersimpan.
        $this->post(route('guru.nilai.simpan'), $q + ['tab' => 'uts', 'nilai' => [$a->id => ['uts' => 120]]])->assertSessionHasErrors();
        $this->assertSame(80, $na->fresh()->uts);
    }

    public function test_guru_tidak_bisa_mengisi_kelas_yang_tidak_diajarnya_dan_admin_melihat_semua_rekap(): void
    {
        [, $mapel, $kelas] = $this->siapkan();
        $s = $this->santri('3 PUTRA');
        $q = ['semester' => $this->semester(), 'kelas' => $kelas->id, 'mapel' => $mapel->id];

        $this->actingAs($this->akun('guru2', 'guru'));
        $this->post(route('guru.nilai.simpan'), $q + ['tab' => 'uts', 'nilai' => [$s->id => ['uts' => 90]]])->assertSessionHasErrors('nilai');
        $this->assertSame(0, Nilai::count());
        $this->get(route('guru.rekap.index', $q))->assertOk()->assertSee('Belum ada kelas');

        $this->actingAs($this->akun('admin', 'admin'));
        $this->get(route('guru.rekap.index', $q))->assertOk()->assertSee($s->nama)->assertSee('Guru1');
        $this->get(route('guru.nilai.index'))->assertForbidden();
    }

    public function test_catatan_harian_milik_sendiri(): void
    {
        [$guru, , $kelas] = $this->siapkan();
        $tugas = GuruMengajar::sole();
        $this->actingAs($guru);
        $hariIni = now()->toDateString();

        $this->post(route('guru.catatan.store'), ['tanggal' => $hariIni, 'penugasan' => $tugas->id, 'materi' => 'Pecahan senilai',
            'kegiatan' => 'Latihan soal', 'tidak_hadir' => 'Ahmad (sakit)'])->assertSessionHas('status');
        $c = CatatanHarianGuru::sole();
        $this->assertSame([$kelas->id, '3 PUTRA'], [$c->kelas_id, $c->kelas_nama]);
        $this->get(route('guru.catatan.index'))->assertOk()->assertSee('Pecahan senilai')->assertSee('Ahmad (sakit)');
        $this->get(route('guru.dashboard'))->assertSee('hari ini sudah diisi');

        $this->post(route('guru.catatan.store'), ['tanggal' => now()->addDay()->toDateString(), 'penugasan' => $tugas->id, 'materi' => 'x'])->assertSessionHasErrors('tanggal');

        $this->put(route('guru.catatan.update', $c), ['tanggal' => $hariIni, 'penugasan' => $tugas->id, 'materi' => 'Pecahan campuran'])->assertSessionHas('status');
        $this->assertSame('Pecahan campuran', $c->fresh()->materi);

        $guru2 = $this->akun('guru2', 'guru');
        $this->actingAs($guru2);
        $this->get(route('guru.catatan.index'))->assertDontSee('Pecahan campuran');
        $this->put(route('guru.catatan.update', $c), ['tanggal' => $hariIni, 'penugasan' => $tugas->id, 'materi' => 'retas'])->assertForbidden();
        $this->delete(route('guru.catatan.destroy', $c))->assertForbidden();
        $this->post(route('guru.catatan.store'), ['tanggal' => $hariIni, 'penugasan' => $tugas->id, 'materi' => 'retas'])->assertStatus(422);
    }

    public function test_bank_soal_bersama_hanya_pembuat_yang_mengubah_dan_cetak_dengan_kunci(): void
    {
        [$guru, $mapel, $kelas] = $this->siapkan();
        $soal = ['mapel_id' => $mapel->id, 'tingkat' => 3, 'topik' => 'Pecahan', 'kesulitan' => 'mudah', 'pertanyaan' => '1/2 + 1/4 = ...',
            'opsi_a' => '1/4', 'opsi_b' => '2/4', 'opsi_c' => '3/4', 'opsi_d' => '1', 'jawaban' => 'c'];
        $this->actingAs($guru);
        $this->post(route('guru.soal.store'), $soal)->assertSessionHas('status');
        $this->post(route('guru.soal.store'), ['jawaban' => 'e'] + $soal)->assertSessionHasErrors('jawaban');
        $s = SoalPg::sole();
        $this->assertSame($guru->id, $s->dibuat_oleh);
        $this->get(route('guru.soal.index'))->assertOk()->assertSee('1/2 + 1/4');

        $cetak = $this->get(route('guru.soal.cetak', ['mapel' => $mapel->id, 'kunci' => 1, 'judul' => 'Ulangan Harian 1']))->assertOk();
        $cetak->assertSee('Ulangan Harian 1')->assertSee('Kunci jawaban')->assertSee('3/4');
        $this->get(route('guru.soal.cetak', ['mapel' => $mapel->id]))->assertDontSee('Kunci jawaban');

        // Guru lain yang mengajar mapel sama melihat soal, tetapi tidak bisa mengubah.
        $guru2 = $this->akun('guru2', 'guru');
        GuruMengajar::create(['user_id' => $guru2->id, 'kelas_id' => Kelas::where('nama', '4')->value('id'), 'mapel_id' => $mapel->id, 'tahun_ajaran_id' => $this->ta->id]);
        $this->actingAs($guru2);
        $this->get(route('guru.soal.index'))->assertSee('1/2 + 1/4')->assertSee('oleh Guru1');
        $this->put(route('guru.soal.update', $s), $soal)->assertForbidden();
        $this->delete(route('guru.soal.destroy', $s))->assertForbidden();

        // Guru yang tidak mengajar mapel itu tidak melihatnya dan tidak bisa menambah soal ke mapel itu.
        $this->actingAs($this->akun('guru3', 'guru'));
        $this->get(route('guru.soal.index'))->assertDontSee('1/2 + 1/4');
        $this->post(route('guru.soal.store'), $soal)->assertSessionHasErrors('mapel_id');
    }
}
