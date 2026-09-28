<?php

namespace Tests\Feature;

use App\Enums\KeputusanTunggakan;
use App\Enums\StatusBankMutasi;
use App\Enums\StatusKeringanan;
use App\Enums\StatusMutasi;
use App\Models\BankMutasi;
use App\Models\JenisTagihan;
use App\Models\Peringatan;
use App\Models\TahunAjaran;
use App\Models\TutupBuku;
use App\Models\User;
use App\Notifiers\LogNotifier;
use App\Services\AkunService;
use App\Services\PeringatanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\KhandaqTestCase;

/** Layar Keuangan: Persetujuan, Tunggakan, Impor mutasi BSI, Tutup buku. */
class LayarKeuanganTest extends KhandaqTestCase
{
    private function staf(string $peran, string $username): User
    {
        return User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@test.local",
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole($peran);
    }

    public function test_persetujuan_beasiswa_dan_pengaju_tidak_bisa_menyetujui_sendiri(): void
    {
        $s = $this->santri();
        $admin = $this->staf('admin', 'admin');
        $ta = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1));
        $k = $this->keringananSvc()->ajukanBeasiswa($s, $ta, 50, null, 'Yatim', $admin);
        $d = $this->keringananSvc()->ajukanDiskon($s, $ta, JenisTagihan::kode(JenisTagihan::DSB), null, 1_000_000, 'Kakak beradik', $this->staf('admin_office', 'office'));

        $keu = $this->staf('keuangan', 'keu');
        $this->actingAs($keu)->get(route('persetujuan.index'))->assertOk()->assertSee($s->nama)->assertSee('50%')->assertSee('Rp1.000.000 per tagihan');
        $this->post(route('persetujuan.setujui', $k))->assertSessionHas('status');
        $this->post(route('persetujuan.tolak', $d), ['catatan' => 'Belum ada bukti'])->assertSessionHas('status');
        $this->assertSame(StatusKeringanan::Disetujui, $k->fresh()->status);
        $this->assertSame(StatusKeringanan::Ditolak, $d->fresh()->status);

        // Keuangan yang mengajukan sendiri tidak bisa menyetujui.
        $keu->givePermissionTo('beasiswa.ajukan');
        $sendiri = $this->keringananSvc()->ajukanBeasiswa($this->santri(), $ta, 25, null, 'Uji', $keu);
        $this->get(route('persetujuan.index'))->assertSee('perlu pemutus lain');
        $this->post(route('persetujuan.setujui', $sendiri))->assertSessionHasErrors('persetujuan');
    }

    public function test_tunggakan_tahap_3_pemulangan_hanya_setelah_wali_membaca(): void
    {
        $s = $this->santri();
        $wali = $this->wali($s);
        foreach (['2025-09-01', '2025-10-01', '2025-11-01'] as $b) {
            $this->generator()->bulanan($this->tgl($b));
        }
        (new PeringatanService(new LogNotifier()))->jalankan($this->tgl('2025-12-01'));
        $p = Peringatan::where('tahap', 'terlambat_3')->firstOrFail();

        $this->actingAs($this->staf('keuangan', 'keu'));
        $this->get(route('tunggakan.index'))->assertOk()->assertSee($s->nama)->assertSee('menunggu keputusan');
        $this->post(route('tunggakan.putuskan', $p), ['keputusan' => 'pemulangan'])->assertSessionHasErrors('tunggakan');

        (new PeringatanService(new LogNotifier()))->tandaiDibaca($p, $wali);
        $this->post(route('tunggakan.putuskan', $p), ['keputusan' => 'pemulangan', 'catatan' => 'Sudah dua kali dihubungi'])->assertSessionHas('status');
        $this->assertSame(KeputusanTunggakan::Pemulangan, $p->fresh()->keputusan);
    }

    public function test_impor_csv_bsi_mencocokkan_setoran_dan_sisanya_ditinjau(): void
    {
        $s = $this->santri('3 PUTRA', '171');
        $setoran = $this->tabungan()->catatSetoran($s, 1_500_171, CarbonImmutable::parse('2026-09-26 19:40'), true, $this->wali($s));
        $csv = "tanggal,no_referensi,deskripsi,debet,kredit,saldo\n"
            ."27/09/2026 07:01,FT001,TRF KHQ 171 SPP,0,1500171,9000000\n"
            ."27/09/2026 08:15,FT002,BI FAST TANPA KODE,0,700000,9700000\n"
            ."27/09/2026 09:00,FT003,BIAYA ADM,5000,0,9695000\n";

        $this->actingAs($this->staf('keuangan', 'keu'));
        $this->post(route('bank.store'), [
            'file' => UploadedFile::fake()->createWithContent('mutasi.csv', $csv),
            'rekening_id' => \App\Models\Rekening::kode('BSI')->id, 'format_tanggal' => 'd/m/Y H:i',
        ])->assertRedirect(route('bank.index'))->assertSessionHas('status', '3 baris baru diimpor: 1 cocok otomatis, 1 perlu ditinjau di Verifikasi setoran.');

        $this->assertSame(StatusMutasi::Terverifikasi, $setoran->fresh()->status);
        $this->assertSame(1_500_171, $s->saldo());
        $this->assertSame(StatusBankMutasi::Ditinjau, BankMutasi::where('no_referensi', 'FT002')->value('status'));
        $this->get(route('bank.index'))->assertOk()->assertSee('mutasi.csv');

        // File yang sama diimpor ulang: tidak ada baris ganda.
        $this->post(route('bank.store'), ['file' => UploadedFile::fake()->createWithContent('mutasi.csv', $csv),
            'rekening_id' => \App\Models\Rekening::kode('BSI')->id, 'format_tanggal' => 'd/m/Y H:i'])->assertSessionHas('status');
        $this->assertSame(3, BankMutasi::count());
    }

    public function test_impor_dengan_kolom_salah_memberi_pesan_jelas(): void
    {
        $this->actingAs($this->staf('keuangan', 'keu'));
        $this->post(route('bank.store'), [
            'file' => UploadedFile::fake()->createWithContent('x.csv', "tgl,ref,nominal\n1,2,3\n"),
            'rekening_id' => \App\Models\Rekening::kode('BSI')->id, 'format_tanggal' => 'd/m/Y H:i',
        ])->assertSessionHasErrors(['file' => "Kolom 'tanggal' tidak ditemukan di file."]);
    }

    public function test_tutup_buku_berurutan_dan_ditolak_bila_ada_setoran_pending(): void
    {
        $s = $this->santri();
        $bulanLalu = CarbonImmutable::now()->startOfMonth()->subMonthNoOverflow();
        $pending = $this->tabungan()->catatSetoran($s, 100000, $bulanLalu->addDays(3), true, $this->wali($s));

        $this->actingAs($keu = $this->staf('keuangan', 'keu'));
        $this->get(route('tutupbuku.index'))->assertOk()->assertSee('1 setoran pending')->assertSee('Masa paralel');
        $this->tabungan()->tolak($pending, $keu, 'uji');
        $this->post(route('tutupbuku.store'), ['bulan' => $bulanLalu->format('Y-m'), 'konfirmasi' => 1])
            ->assertSessionHasErrors(['tutupbuku' => 'Tutup buku baru bisa dilakukan setelah aplikasi berpindah ke mode produksi (KHANDAQ_MODE=produksi).']);
        $this->assertFalse(TutupBuku::exists(), 'masa paralel: tidak boleh tutup buku');

        config(['khandaq.mode' => 'produksi']);
        $pending = $this->tabungan()->catatSetoran($s, 100000, $bulanLalu->addDays(4), true, $this->wali($s));
        $this->post(route('tutupbuku.store'), ['bulan' => $bulanLalu->format('Y-m'), 'konfirmasi' => 1])->assertSessionHasErrors('tutupbuku');

        $this->tabungan()->tolak($pending, $keu, 'uji');
        $this->post(route('tutupbuku.store'), ['bulan' => $bulanLalu->format('Y-m'), 'konfirmasi' => 1])->assertSessionHas('status');
        $this->assertTrue(TutupBuku::exists());

        // Bulan berjalan belum bisa ditutup.
        $this->post(route('tutupbuku.store'), ['bulan' => CarbonImmutable::now()->format('Y-m'), 'konfirmasi' => 1])->assertSessionHasErrors('tutupbuku');
    }

    public function test_layar_keuangan_tertutup_untuk_admin_office(): void
    {
        $this->actingAs($this->staf('admin_office', 'kasir'));
        foreach (['persetujuan.index', 'tunggakan.index', 'bank.index', 'tutupbuku.index'] as $r) {
            $this->get(route($r))->assertForbidden();
        }
    }
}
