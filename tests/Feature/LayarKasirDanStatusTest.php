<?php

namespace Tests\Feature;

use App\Enums\StatusBankMutasi;
use App\Enums\StatusMutasi;
use App\Models\BankMutasi;
use App\Models\Rekening;
use App\Models\TabunganMutasi;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\AkunService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\KhandaqTestCase;

/** Layar Status pembayaran, Kasir santri, dan Verifikasi setoran. */
class LayarKasirDanStatusTest extends KhandaqTestCase
{
    private function staf(string $peran, string $username): User
    {
        $u = User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@test.local",
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false]);

        return $u->assignRole($peran);
    }

    public function test_status_pembayaran_menampilkan_kisi_spp_dan_dsb(): void
    {
        $lancar = $this->santri('3 PUTRA');
        $nunggak = $this->santri('3 PUTRA');
        foreach (['2025-07-01', '2025-08-01'] as $b) {
            $this->generator()->bulanan($this->tgl($b));
        }
        $this->tabungan()->catatSetoran($lancar, 5_000_000, $this->tgl('2025-08-05'), false, $this->adminOffice());
        $ta = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1));

        $this->actingAs($this->staf('keuangan', 'keu'));
        $this->get(route('laporan.status', ['ta' => $ta->id]))->assertOk()
            ->assertSee($nunggak->nama)->assertSee($lancar->nama)->assertSee('1 santri menunggak');
        $this->get(route('laporan.status', ['ta' => $ta->id, 'status' => 'menunggak']))->assertOk()
            ->assertSee($nunggak->nama)->assertDontSee($lancar->nama);
        $this->get(route('laporan.status', ['tab' => 'dsb']))->assertOk()->assertSee('tagihan belum lunas');
    }

    public function test_kasir_setor_tunai_tarik_uang_saku_dan_saldo_tidak_boleh_minus(): void
    {
        $s = $this->santri('3 PUTRA', '171');
        $this->actingAs($this->staf('admin_office', 'kasir'));

        $this->get(route('kasir.index', ['q' => '171']))->assertOk()->assertSee($s->nama)->assertSee('Rp0');
        $this->post(route('kasir.setor', $s), ['nominal' => 200000, 'cara' => 'tunai'])
            ->assertRedirect(route('kasir.index', ['santri' => $s->id]))->assertSessionHas('status');
        $this->assertSame(200000, $s->saldo());

        $this->post(route('kasir.tarik', $s), ['nominal' => 50000])->assertSessionHas('status');
        $this->assertSame(150000, $s->saldo());
        // Setoran = "Masuk saldo"; penarikan/potongan = "Dipotong dari saldo".
        $this->get(route('kasir.index', ['santri' => $s->id]))->assertSee('Masuk saldo')->assertSee('Dipotong dari saldo');

        $this->from(route('kasir.index', ['santri' => $s->id]))
            ->post(route('kasir.tarik', $s), ['nominal' => 500000])->assertSessionHasErrors(['kasir' => 'Saldo tidak cukup untuk penarikan.']);
        $this->assertSame(150000, $s->saldo());
    }

    public function test_transfer_dicatat_kasir_lalu_diverifikasi_petugas_lain_bukan_pencatat(): void
    {
        Storage::fake();
        $s = $this->santri();
        $kasir = $this->staf('admin_office', 'kasir');
        $this->actingAs($kasir)->post(route('kasir.setor', $s), [
            'nominal' => 1_500_171, 'cara' => 'transfer', 'bukti' => UploadedFile::fake()->image('bukti.jpg'),
        ])->assertSessionHas('status');
        $m = TabunganMutasi::where('santri_id', $s->id)->firstOrFail();
        $this->assertSame(StatusMutasi::Pending, $m->status);
        $this->assertSame(0, $s->saldo(), 'transfer belum dihitung sebelum diverifikasi');
        $this->get(route('setoran.bukti', $m))->assertOk();

        // Pencatat tidak boleh memverifikasi setorannya sendiri.
        $this->get(route('verifikasi.index'))->assertOk()->assertSee('perlu petugas lain');
        $this->post(route('verifikasi.setoran', $m))->assertSessionHasErrors('verifikasi');

        $this->actingAs($this->staf('keuangan', 'keu'))->post(route('verifikasi.setoran', $m))->assertSessionHas('status');
        $this->assertSame(1_500_171, $s->saldo());
    }

    public function test_tolak_setoran_dan_tinjau_mutasi_bsi(): void
    {
        $s = $this->santri('3 PUTRA', '184');
        $kasir = $this->staf('admin_office', 'kasir');
        $m = $this->tabungan()->catatSetoran($s, 300000, CarbonImmutable::now(), true, $kasir);
        $keu = $this->staf('keuangan', 'keu');
        $this->actingAs($keu)->post(route('verifikasi.tolak', $m), ['alasan' => 'Tidak ada di rekening'])->assertSessionHas('status');
        $this->assertSame(StatusMutasi::Ditolak, $m->fresh()->status);

        $bsi = Rekening::kode('BSI');
        $b1 = BankMutasi::create(['rekening_id' => $bsi->id, 'tanggal' => now(), 'no_referensi' => 'FT1', 'deskripsi' => 'TRF KHQ 184', 'kredit' => 1_000_184, 'status' => StatusBankMutasi::Ditinjau, 'santri_id_terdeteksi' => $s->id]);
        $b2 = BankMutasi::create(['rekening_id' => $bsi->id, 'tanggal' => now(), 'no_referensi' => 'FT2', 'deskripsi' => 'BIAYA ADM', 'kredit' => 5000, 'status' => StatusBankMutasi::Ditinjau]);
        $this->get(route('verifikasi.index'))->assertOk()->assertSee('TRF KHQ 184');

        $this->post(route('verifikasi.terima', $b1), ['santri_id' => $s->id])->assertSessionHas('status');
        $this->assertSame(1_000_184, $s->saldo());
        $this->post(route('verifikasi.abaikan', $b2), ['catatan' => 'biaya admin bank'])->assertSessionHas('status');
        $this->assertSame(StatusBankMutasi::Diabaikan, $b2->fresh()->status);
    }

    public function test_akses_layar_mengikuti_izin(): void
    {
        $this->actingAs($this->staf('keuangan', 'keu'));
        $this->get(route('kasir.index'))->assertForbidden();          // Keuangan tidak memegang setoran.catat
        $this->get(route('verifikasi.index'))->assertOk();
        $this->actingAs($this->staf('admin_office', 'kasir'));
        $this->get(route('laporan.status'))->assertForbidden();
    }
}
