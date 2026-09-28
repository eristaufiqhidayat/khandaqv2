<?php

namespace Tests\Feature;

use App\Models\KalenderAkademik;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\AkunService;
use Carbon\CarbonImmutable;
use Tests\KhandaqTestCase;

/** Portal wali bergaya v1: Menu Utama -> Raport, Tabungan, Kalender, Data. */
class PortalWaliTest extends KhandaqTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_menu_dan_halaman_hanya_menampilkan_anak_sendiri(): void
    {
        CarbonImmutable::setTestNow('2025-10-15 09:00');
        TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1))->update(['aktif' => true]);
        [$anak, $lain] = [$this->santri('3 PUTRA'), $this->santri('1 PUTRA')];
        $wali = $this->wali($anak)->assignRole('wali_santri');
        $wali->update(['wajib_ganti_password' => false, 'pekerjaan' => 'Petani']);
        $this->tabungan()->catatSetoran($anak, 750_000, $this->tgl('2025-10-01'), false, $this->adminOffice());
        KalenderAkademik::create(['tanggal_mulai' => '2025-12-20', 'tanggal_selesai' => '2026-01-04', 'kegiatan' => 'Libur semester ganjil']);
        KalenderAkademik::create(['tanggal_mulai' => '2025-08-17', 'kegiatan' => 'Upacara kemerdekaan']);

        $this->actingAs($wali)->get(route('wali.beranda'))->assertOk()
            ->assertSee('Menu Utama')->assertSee(route('wali.raport'))->assertSee(route('wali.tabungan'))
            ->assertSee(route('wali.kalender'))->assertSee(route('wali.data'))->assertSee($anak->nama)->assertDontSee($lain->nama);
        $this->get(route('wali.tabungan'))->assertOk()->assertSee('Rp750.000')->assertSee('Setoran tunai')->assertDontSee($lain->nama);
        $this->get(route('wali.raport'))->assertOk()->assertSee('Belum ada raport');
        $this->get(route('wali.kalender'))->assertOk()->assertSeeInOrder(['Akan datang', 'Libur semester ganjil', 'Sudah lewat', 'Upacara kemerdekaan']);
        $this->get(route('wali.data'))->assertOk()->assertSee($anak->nis)->assertSee('Petani')->assertDontSee($lain->nama);

        $staf = User::create(['name' => 'Office', 'username' => 'office', 'email' => 'o@test.local', 'password' => AkunService::hash('rahasia123'),
            'wajib_ganti_password' => false])->assignRole('admin_office');
        $this->actingAs($staf)->get(route('wali.tabungan'))->assertForbidden();
    }

    public function test_kelola_kalender_akademik(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'a@test.local', 'password' => AkunService::hash('rahasia123'),
            'wajib_ganti_password' => false])->assignRole('admin');
        $ta = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1));
        $this->actingAs($admin)->get(route('kalender.index', ['ta' => $ta->id]))->assertOk()->assertSee('Belum ada kegiatan');
        $this->post(route('kalender.store'), ['kegiatan' => 'PAS ganjil', 'tanggal_mulai' => '2025-12-01', 'tanggal_selesai' => '2025-12-06'])->assertSessionHas('status');
        $k = KalenderAkademik::sole();
        $this->get(route('kalender.index', ['ta' => $ta->id]))->assertSee('PAS ganjil')->assertSee('01–06 Desember 2025');
        $this->post(route('kalender.store'), ['kegiatan' => 'x', 'tanggal_mulai' => '2025-12-06', 'tanggal_selesai' => '2025-12-01'])->assertSessionHasErrors('tanggal_selesai');
        $this->put(route('kalender.update', $k), ['kegiatan' => 'PAS semester ganjil', 'tanggal_mulai' => '2025-12-01', 'tanggal_selesai' => '2025-12-01']);
        $this->assertSame(['PAS semester ganjil', null], [$k->fresh()->kegiatan, $k->fresh()->tanggal_selesai]);
        $this->delete(route('kalender.destroy', $k))->assertSessionHas('status');
        $this->assertSame(0, KalenderAkademik::count());
        $this->actingAs(User::create(['name' => 'K', 'username' => 'keu', 'email' => 'k@test.local', 'password' => AkunService::hash('rahasia123'),
            'wajib_ganti_password' => false])->assignRole('keuangan'))->get(route('kalender.index'))->assertForbidden();
    }
}
