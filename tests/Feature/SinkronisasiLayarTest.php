<?php

namespace Tests\Feature;

use App\Jobs\SinkronkanDataLama;
use App\Models\MigrasiRun;
use App\Models\User;
use App\Services\AkunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\KhandaqTestCase;

/** Layar Sinkronisasi: proses yang macet tidak boleh mengunci tombol selamanya. */
class SinkronisasiLayarTest extends KhandaqTestCase
{
    private function akunAdmin(): User
    {
        return User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'admin@test.local',
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole('admin');
    }

    public function test_antrean_yang_belum_diproses_diberi_petunjuk_dan_bisa_dibatalkan(): void
    {
        Queue::fake();
        $this->actingAs($admin = $this->akunAdmin());
        $this->postJson(route('sinkronisasi.store'), ['konfirmasi' => 'GANTI'])->assertStatus(202);
        Queue::assertPushed(SinkronkanDataLama::class);
        $this->postJson(route('sinkronisasi.store'), ['konfirmasi' => 'GANTI'])->assertStatus(409);

        $run = MigrasiRun::firstOrFail();
        $this->travel(2)->minutes();
        $this->get(route('sinkronisasi.index'))->assertOk()->assertSee('Pekerja antrean belum berjalan')->assertSee('Batalkan proses');

        $this->post(route('sinkronisasi.batal', $run))->assertRedirect(route('sinkronisasi.index'));
        $this->assertSame('gagal', $run->fresh()->status);
        $this->postJson(route('sinkronisasi.store'), ['konfirmasi' => 'GANTI'])->assertStatus(202);
    }

    public function test_proses_yang_mati_di_tengah_jalan_otomatis_dianggap_gagal(): void
    {
        $admin = $this->akunAdmin();
        $run = MigrasiRun::create(['status' => 'berjalan', 'tahap' => 'Raport', 'dijalankan_oleh' => $admin->id]);
        $this->travel(MigrasiRun::MENIT_MACET + 1)->minutes();

        $this->actingAs($admin)->get(route('sinkronisasi.index'))->assertOk();
        $this->assertSame('gagal', $run->fresh()->status);
        $this->assertStringContainsString('Terhenti tanpa kabar', $run->fresh()->galat);
    }

    public function test_job_yang_gagal_menandai_proses_gagal(): void
    {
        $run = MigrasiRun::create(['status' => 'berjalan', 'dijalankan_oleh' => $this->akunAdmin()->id]);
        (new SinkronkanDataLama($run->id, 1))->failed(new \RuntimeException('Allowed memory size exhausted'));
        $this->assertSame('gagal', $run->fresh()->status);
        $this->assertStringContainsString('Allowed memory size', $run->fresh()->galat);
    }
}
