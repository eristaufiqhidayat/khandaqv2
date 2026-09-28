<?php

namespace Tests\Feature;

use App\Contracts\WaGateway;
use App\Enums\StatusSantri;
use App\Models\Akun;
use App\Models\Dana;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\PengecualianPotongan;
use App\Models\Pengeluaran;
use App\Models\Rekening;
use App\Models\Semester;
use App\Models\Tarif;
use App\Models\TahunAjaran;
use App\Models\TutupBuku;
use App\Models\User;
use App\Services\AkunService;
use App\Services\Whatsapp\PengirimWa;
use App\Whatsapp\LogGateway;
use Carbon\CarbonImmutable;
use Tests\KhandaqTestCase;

/** Layar Admin & Keuangan: Tarif, Potongan otomatis, Tahun ajaran & kelas, Kenaikan, Pengguna, Hak akses, Pengeluaran, Rekening. */
class LayarPengaturanTest extends KhandaqTestCase
{
    private LogGateway $wa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wa = new class extends LogGateway {
            public function nama(): string { return 'ngirimwa'; }
        };
        $this->app->instance(PengirimWa::class, new PengirimWa($this->wa));
    }

    private function staf(string $peran, string $username): User
    {
        return User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@test.local", 'telepon' => '0811'.crc32($username),
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole($peran);
    }

    public function test_tarif_disimpan_dan_disalin_ke_tahun_ajaran_baru(): void
    {
        $this->actingAs($this->staf('admin', 'admin'));
        $lama = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1));
        $baru = TahunAjaran::untukTanggal(CarbonImmutable::create(2026, 7, 1));
        $this->get(route('tarif.index', ['ta' => $baru->id]))->assertOk()->assertSee('Salin tarif 2025/2026');
        $this->post(route('tarif.salin', $baru))->assertSessionHas('status');
        $this->assertSame(Tarif::where('tahun_ajaran_id', $lama->id)->get()->unique(fn ($t) => $t->jenis_tagihan_id.'|'.$t->kelas_id)->count(),
            Tarif::where('tahun_ajaran_id', $baru->id)->count());

        $spp = JenisTagihan::kode('SPP');
        $this->post(route('tarif.store'), ['tahun_ajaran_id' => $baru->id, 'jenis_tagihan_id' => $spp->id, 'berlaku_mulai' => '2027-01-01', 'nominal' => 1_900_000])
            ->assertSessionHas('status');
        $this->assertSame(1_900_000, $spp->tarifUntuk($baru, null, CarbonImmutable::create(2027, 2, 1))->nominal);
        $this->post(route('tarif.store'), ['tahun_ajaran_id' => $baru->id, 'jenis_tagihan_id' => $spp->id, 'berlaku_mulai' => '2030-01-01', 'nominal' => 1])
            ->assertSessionHasErrors('tarif');
    }

    public function test_tarif_yang_sudah_dipakai_tagihan_tidak_bisa_dihapus(): void
    {
        $this->santri();
        $this->generator()->bulanan($this->tgl('2025-07-01'));
        $dipakai = Tarif::whereIn('id', \App\Models\Tagihan::pluck('tarif_id'))->firstOrFail();
        $this->actingAs($this->staf('admin', 'admin'))->delete(route('tarif.destroy', $dipakai))->assertSessionHasErrors('tarif');
        $this->assertModelExists($dipakai);
    }

    public function test_pengecualian_potongan_dan_akhiri(): void
    {
        $s = $this->santri();
        $this->actingAs($this->staf('admin', 'admin'));
        $laundry = JenisTagihan::kode('LAUNDRY');
        $this->post(route('potongan.kecualikan'), ['santri_id' => $s->id, 'jenis_tagihan_id' => $laundry->id, 'mulai' => '2025-07-01', 'alasan' => 'Tidak ikut laundry'])
            ->assertSessionHas('status');
        $this->generator()->bulanan($this->tgl('2025-08-01'));
        $this->assertFalse($s->tagihan()->where('jenis_tagihan_id', $laundry->id)->exists(), 'laundry tidak ditagih');
        $this->get(route('potongan.index'))->assertOk()->assertSee('Tidak ikut laundry');
        $this->post(route('potongan.akhiri', PengecualianPotongan::sole()))->assertSessionHas('status');
    }

    public function test_aktifkan_semester_tambah_kelas_dan_kenaikan_kelas(): void
    {
        $this->actingAs($this->staf('admin', 'admin'));
        $ta = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1));
        $sem2 = $ta->semester()->where('nomor', 2)->first();
        $this->post(route('periode.aktifkan', $sem2))->assertSessionHas('status');
        $this->assertTrue(Semester::find($sem2->id)->aktif);
        $this->post(route('periode.kelas.store'), ['nama' => '7 KHUSUS', 'tingkat' => 6])->assertSessionHas('status');
        $this->assertDatabaseHas('kelas', ['nama' => '7 KHUSUS']);

        $s = $this->santri('3 PUTRA');
        $baru = TahunAjaran::untukTanggal(CarbonImmutable::create(2026, 7, 1));
        $this->actingAs($this->staf('admin_office', 'office'));
        $html = $this->get(route('kenaikan.index', ['ke' => $baru->id]))->assertOk()->assertSee('3 PUTRA')->getContent();
        // Saran tidak boleh menyilang putra/putri: 1 PUTRI -> 2 PUTRI.
        preg_match('/name="peta\['.Kelas::where('nama', '1 PUTRI')->value('id').'\]".*?<\/select>/s', $html, $m);
        $this->assertMatchesRegularExpression('/value="'.Kelas::where('nama', '2 PUTRI')->value('id').'"\s+selected/', $m[0]);
        $this->post(route('kenaikan.store'), ['dari' => $ta->id, 'ke' => $baru->id,
            'peta' => [Kelas::where('nama', '3 PUTRA')->value('id') => Kelas::where('nama', '4')->value('id') ?? Kelas::where('nama', '4 PUTRA')->value('id')]])
            ->assertSessionHas('status', fn ($st) => str_contains($st, '1 santri naik kelas'));
        $this->assertNotNull($s->kelasPada($baru));
    }

    public function test_admin_membuat_staf_dengan_password_lewat_whatsapp_dan_pengaman_admin_terakhir(): void
    {
        $admin = $this->staf('admin', 'admin');
        $this->actingAs($admin)->post(route('pengguna.store'), ['name' => 'Bu Keu', 'username' => 'bukeu', 'email' => 'bukeu@test.local',
            'telepon' => '081377778888', 'peran' => 'keuangan'])->assertSessionHas('status', fn ($s) => str_contains($s, 'dikirim ke WhatsApp'));
        $baru = User::where('username', 'bukeu')->sole();
        $this->assertTrue($baru->hasRole('keuangan'));
        $this->assertCount(1, $this->wa->terkirim);

        $this->post(route('pengguna.peran', $baru), ['peran' => 'admin_office'])->assertSessionHas('status');
        $this->assertTrue($baru->fresh()->hasRole('admin_office'));
        $this->post(route('pengguna.aktif', $admin))->assertSessionHasErrors('pengguna');

        $admin2 = $this->staf('admin', 'admin2');
        $this->post(route('pengguna.aktif', $admin2))->assertSessionHas('status');
        $this->post(route('pengguna.peran', $admin2), ['peran' => 'keuangan'])->assertSessionHas('status');
        $this->assertTrue(User::find($admin2->id)->hasRole('keuangan'));
        $this->post(route('pengguna.peran', $admin), ['peran' => 'keuangan'])->assertSessionHasErrors('pengguna'); // akun sendiri
    }

    public function test_hak_akses_memindahkan_menu_tarif_ke_keuangan(): void
    {
        $keu = $this->staf('keuangan', 'keu');
        $this->actingAs($keu)->get(route('tarif.index'))->assertForbidden();

        $this->actingAs($this->staf('admin', 'admin'));
        $this->get(route('hakakses.index'))->assertOk()->assertSee('tarif.kelola');
        $this->post(route('hakakses.update'), ['peran' => 'keuangan', 'izin' => 'tarif.kelola', 'beri' => 1])->assertSessionHas('status');
        $this->post(route('hakakses.update'), ['peran' => 'admin', 'izin' => 'tarif.kelola', 'beri' => 0])->assertSessionHas('status');
        $this->post(route('hakakses.update'), ['peran' => 'admin', 'izin' => 'hak_akses.kelola', 'beri' => 0])->assertSessionHasErrors('hakakses');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($keu->fresh())->get(route('tarif.index'))->assertOk();
    }

    public function test_pengeluaran_dicatat_dan_bulan_tertutup_terkunci(): void
    {
        $this->actingAs($this->staf('keuangan', 'keu'));
        $data = ['tanggal' => now()->toDateString(), 'dana_id' => Dana::where('kode', 'SPP')->value('id'), 'rekening_id' => Rekening::kode('KAS')->id,
            'nominal' => 350000, 'keterangan' => 'Beli spidol'];
        $this->post(route('pengeluaran.store'), $data)->assertSessionHas('status');
        $this->get(route('pengeluaran.index'))->assertOk()->assertSee('Beli spidol')->assertSee('Rp350.000');

        TutupBuku::create(['bulan' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'saldo_titipan' => 0, 'ditutup_oleh' => auth()->id()]);
        $this->post(route('pengeluaran.store'), array_merge($data, ['tanggal' => now()->subMonthNoOverflow()->toDateString()]))->assertSessionHasErrors('pengeluaran');
        $this->assertSame(1, Pengeluaran::count());
    }

    public function test_master_keuangan_tambah_rekening_dan_akun(): void
    {
        $this->actingAs($this->staf('keuangan', 'keu'));
        $this->post(route('masterkeu.store', 'rekening'), ['kode' => 'BSI2', 'nama' => 'BSI Operasional', 'jenis' => 'bank'])->assertSessionHas('status');
        $this->post(route('masterkeu.store', 'akun'), ['kode' => 'ATK', 'nama' => 'Alat tulis'])->assertSessionHas('status');
        $akun = Akun::where('kode', 'ATK')->sole();
        $this->put(route('masterkeu.update', ['akun', $akun->id]), ['nama' => 'Alat tulis kantor'])->assertSessionHas('status');
        $this->assertSame('Alat tulis kantor', $akun->fresh()->nama);
        $this->get(route('masterkeu.index'))->assertOk()->assertSee('BSI Operasional');
    }

    public function test_semua_menu_punya_layar_sungguhan(): void
    {
        $this->actingAs($this->staf('admin', 'admin'));
        foreach (config('khandaq-menu') as $m) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($m['route']), $m['route']);
        }
        foreach (['tarif.index', 'potongan.index', 'periode.index', 'pengguna.index', 'hakakses.index', 'sinkronisasi.index'] as $r) {
            $this->get(route($r))->assertOk()->assertDontSee('sedang dibangun');
        }
    }

    public function test_tab_wali_di_layar_pengguna(): void
    {
        $anak = $this->santri('3 PUTRA');
        $w = $this->wali($anak)->assignRole('wali_santri');
        $w->update(['name' => 'Bapak Fulan', 'username' => 'fulan', 'telepon' => '081299990001']);
        $tanpa = $this->wali($this->santri('1 PUTRA'))->assignRole('wali_santri');
        $tanpa->update(['name' => 'Ibu Tanpa Nomor', 'telepon' => null]);

        // Admin: kedua tab tampil, tab wali hanya baca (reset/nonaktif milik Admin Office).
        $admin = $this->staf('admin', 'admin');
        $this->actingAs($admin)->get(route('pengguna.index'))->assertOk()->assertSee('Tambah staf')->assertDontSee('Bapak Fulan');
        $this->get(route('pengguna.index', ['tab' => 'wali']))->assertOk()
            ->assertSee('Bapak Fulan')->assertSee($anak->nama)->assertSee('Ibu Tanpa Nomor')->assertDontSee('Tambah staf')
            ->assertSee('bawaan: Admin Office');
        $this->get(route('pengguna.index', ['tab' => 'wali', 'q' => $anak->nama]))->assertSee('Bapak Fulan')->assertDontSee('Ibu Tanpa Nomor');
        $this->get(route('pengguna.index', ['tab' => 'wali', 'status' => 'tanpa_wa']))->assertSee('Ibu Tanpa Nomor')->assertDontSee('Bapak Fulan');
        $this->post(route('pengguna.wali.aktif', $w))->assertForbidden();
        // Aksi tab Staf tidak bisa dipakai pada akun wali (mis. menjadikan wali admin).
        $this->post(route('pengguna.peran', $w), ['peran' => 'admin'])->assertNotFound();
        $this->assertFalse($w->fresh()->hasRole('admin'));

        // Admin Office: menu Pengguna tampil, langsung tab wali, tanpa tab staf; bisa nonaktifkan & reset.
        $office = $this->staf('admin_office', 'office');
        $this->actingAs($office)->get(route('pengguna.index'))->assertOk()->assertSee('Bapak Fulan')->assertDontSee('Tambah staf');
        $this->assertContains('pengguna.index', array_column(\App\Support\MenuStaf::untuk($office), 'route'));
        $this->post(route('pengguna.store'), [])->assertForbidden();
        $this->post(route('pengguna.wali.aktif', $w))->assertSessionHas('status');
        $this->assertFalse($w->fresh()->aktif);
        $this->post(route('pengguna.wali.reset', $w))->assertSessionHas('status');
        $this->assertTrue($w->fresh()->wajib_ganti_password);
        $this->post(route('pengguna.wali.aktif', $admin))->assertNotFound();

        // Keuangan tidak punya kedua izin.
        $this->actingAs($this->staf('keuangan', 'keu'))->get(route('pengguna.index'))->assertForbidden();
    }
}
