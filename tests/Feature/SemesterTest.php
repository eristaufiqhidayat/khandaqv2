<?php

namespace Tests\Feature;

use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\AkunService;
use Carbon\CarbonImmutable;
use Tests\KhandaqTestCase;

/** Nama semester selalu menyebut tahun ajaran; dropdown raport tidak menampilkan tahun ajaran masa depan. */
class SemesterTest extends KhandaqTestCase
{
    private function staf(string $peran): User
    {
        return User::create(['name' => $peran, 'username' => $peran, 'email' => "{$peran}@test.local",
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole($peran);
    }

    public function test_label_semester_menyebut_tahun_ajaran_walau_nama_lama_tidak(): void
    {
        $ta = TahunAjaran::untukTanggal(CarbonImmutable::create(2023, 7, 1));
        $s = $ta->semester()->where('nomor', 1)->first();
        $this->assertSame('Semester 1 (Ganjil) 2023/2024', $s->nama);
        $s->update(['nama' => 'semester 1']); // seperti data lama
        $this->assertSame('semester 1 2023/2024', $s->fresh()->label);
        $this->assertSame('Semester 2 (Genap) 2023/2024', Semester::namaBaku(2, '2023/2024'));
    }

    public function test_dropdown_raport_tanpa_semester_masa_depan(): void
    {
        $lalu = TahunAjaran::untukTanggal(CarbonImmutable::now()->subYear());
        $depan = TahunAjaran::untukTanggal(CarbonImmutable::now()->addYears(3));
        $html = $this->actingAs($this->staf('admin_office'))->get(route('raport.index'))->assertOk()->getContent();
        $this->assertStringContainsString($lalu->nama, $html);
        $this->assertStringNotContainsString($depan->nama, $html);
    }

    public function test_siapkan_tahun_ajaran_tidak_menumpuk_ke_depan(): void
    {
        $this->actingAs($this->staf('admin'));
        $this->post(route('periode.siapkan'));
        $this->post(route('periode.siapkan'));
        $this->post(route('periode.siapkan'));
        $berjalan = TahunAjaran::tahunMulaiUntuk(CarbonImmutable::now());
        $this->assertSame($berjalan + 1, (int) TahunAjaran::max('tahun_mulai'));
    }
}
