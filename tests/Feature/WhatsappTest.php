<?php

namespace Tests\Feature;

use App\Enums\Izin;
use App\Enums\StatusSantri;
use App\Exceptions\AturanDilanggar;
use App\Models\Peringatan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaPesan;
use App\Models\WaWebhook;
use App\Notifiers\WhatsappNotifier;
use App\Services\AkunService;
use App\Services\PeringatanService;
use App\Services\Whatsapp\PengirimWa;
use App\Services\Whatsapp\SiaranWaService;
use App\Services\Whatsapp\WebhookWaService;
use App\Whatsapp\HasilKirim;
use App\Whatsapp\LogGateway;
use Carbon\CarbonImmutable;
use Tests\KhandaqTestCase;

class WhatsappTest extends KhandaqTestCase
{
    private LogGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new LogGateway();
        TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1))->update(['aktif' => true]);
    }

    private function svc(): SiaranWaService
    {
        $pengirim = new PengirimWa($this->gateway);

        // Antrean dijalankan langsung (di Laravel: job KirimPesanWa dengan jeda antar-nomor).
        return new SiaranWaService($pengirim, fn (WaPesan $p) => $pengirim->kirim($p));
    }

    private function petugas(): User
    {
        return $this->beriIzin($this->buatUser('Office WA'), Izin::WaSiaran);
    }

    private function waliDengan(?string $telepon = null, bool $aktif = true, ...$anak): User
    {
        $w = $this->wali(...$anak);
        $w->update(['telepon' => $telepon, 'aktif' => $aktif]);

        return $w;
    }

    public function test_tujuan_diambil_dari_wali_santri_aktif_satu_pesan_per_nomor(): void
    {
        [$a, $b, $c, $keluar] = [$this->santri('3 PUTRA'), $this->santri('3 PUTRA'), $this->santri('1 PUTRA'), $this->santri('1 PUTRA')];
        $keluar->update(['status' => StatusSantri::Keluar]);
        $this->waliDengan('081111', true, $a, $b);          // kakak-adik: 1 pesan
        $this->waliDengan(null, true, $c);                  // belum ada nomor
        $this->waliDengan('083333', false, $c);             // akun dinonaktifkan
        $this->waliDengan('084444', true, $keluar);         // anaknya sudah keluar

        $h = $this->svc()->tujuan(['jenis' => 'semua_wali']);

        $this->assertCount(1, $h['tujuan']);
        $this->assertSame('081111', $h['tujuan'][0]['telepon']);
        $this->assertSame("{$a->nama} dan {$b->nama}", $h['tujuan'][0]['nama_santri']);
        $this->assertSame(1, $h['tanpa_nomor']);
    }

    public function test_sasaran_per_kelas_dan_penunggak(): void
    {
        [$a, $b] = [$this->santri('3 PUTRA'), $this->santri('1 PUTRA')];
        $this->waliDengan('081111', true, $a);
        $this->waliDengan('082222', true, $b);
        $kelas3 = \App\Models\Kelas::where('nama', '3 PUTRA')->value('id');
        $this->assertSame(['081111'], $this->svc()->tujuan(['jenis' => 'kelas', 'kelas_ids' => [$kelas3]])['tujuan']->pluck('telepon')->all());

        foreach (['2025-09-01', '2025-10-01'] as $bln) {
            $this->generator()->bulanan($this->tgl($bln));
        }
        // b lunas: saldo cukup lalu dipotong
        $this->tabungan()->catatSetoran($b, 5_000_000, $this->tgl('2025-10-05'), false, $this->adminOffice());
        $this->tabungan()->alokasiOtomatis($b, $this->tgl('2025-10-05'));

        $this->assertSame(['081111'], $this->svc()->tujuan(['jenis' => 'tunggakan', 'min_bulan' => 2])['tujuan']->pluck('telepon')->all());
        $this->assertCount(0, $this->svc()->tujuan(['jenis' => 'tunggakan', 'min_bulan' => 3])['tujuan']);
    }

    public function test_kirim_siaran_mencatat_status_per_nomor_dan_personalisasi(): void
    {
        [$a, $b] = [$this->santri('3 PUTRA'), $this->santri('3 PUTRA')];
        $this->waliDengan('081111', true, $a);
        $this->waliDengan('082000', true, $b); // LogGateway: nomor berakhiran 000 gagal
        $svc = $this->svc();
        $siaran = $svc->buat($this->petugas(), 'Libur', 'Yth. {nama_wali}, {nama_santri} libur mulai 1 Okt.', ['jenis' => 'semua_wali']);

        $siaran = $svc->kirim($siaran, $this->petugas());

        $this->assertSame(['selesai', 2, 1, 1], [$siaran->status, $siaran->jumlah_tujuan, $siaran->jumlah_terkirim, $siaran->jumlah_gagal]);
        $this->assertStringContainsString($a->nama.' libur', $this->gateway->terkirim[0]['isi']);
        $this->assertSame('Nomor tidak terdaftar di WhatsApp', WaPesan::where('telepon', '082000')->value('galat'));

        $this->expectException(AturanDilanggar::class);
        $svc->kirim($siaran, $this->petugas()); // tidak bisa dikirim dua kali
    }

    public function test_hanya_pemegang_izin_yang_bisa_membuat_siaran(): void
    {
        $this->expectException(AturanDilanggar::class);
        $this->svc()->buat($this->buatUser('Tamu'), 'x', 'y', ['jenis' => 'semua_wali']);
    }

    public function test_meta_wajib_template(): void
    {
        $meta = new class extends LogGateway {
            public function nama(): string { return 'meta'; }
        };
        $svc = new SiaranWaService(new PengirimWa($meta), fn () => null);
        $this->expectException(AturanDilanggar::class);
        $svc->buat($this->petugas(), 'x', 'y', ['jenis' => 'semua_wali']);
    }

    public function test_webhook_meta_status_tidak_mundur_dan_dibaca_menjadi_bukti_baca_peringatan(): void
    {
        $s = $this->santri('3 PUTRA');
        $this->waliDengan('081111', true, $s);
        foreach (['2025-09-01', '2025-10-01', '2025-11-01'] as $bln) {
            $this->generator()->bulanan($this->tgl($bln));
        }
        (new PeringatanService(new WhatsappNotifier(new PengirimWa($this->gateway))))->jalankan($this->tgl('2025-12-01'));
        $pesan = WaPesan::whereNotNull('peringatan_id')->firstOrFail();
        $this->assertSame('terkirim', $pesan->status);
        $this->assertNotNull(Peringatan::find($pesan->peringatan_id)->dikirim_pada);

        $wh = new WebhookWaService();
        $status = fn (string $st, int $ts) => ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
            'statuses' => [['id' => $pesan->id_vendor, 'status' => $st, 'timestamp' => (string) $ts, 'recipient_id' => '6281111']]]]]]]];
        $wh->terima('meta', $status('read', 1764570000));
        $wh->terima('meta', $status('delivered', 1764569000)); // datang terlambat

        $this->assertSame('dibaca', $pesan->fresh()->status);
        $this->assertNotNull(Peringatan::find($pesan->peringatan_id)->dibaca_pada, 'centang biru = bukti wali sudah membaca');
        $this->assertSame(2, WaWebhook::where('diproses', true)->count());
    }

    public function test_balasan_wali_tersimpan_dan_tidak_ganda(): void
    {
        $w = $this->waliDengan('081234567', true, $this->santri());
        $masuk = ['entry' => [['changes' => [['value' => ['messages' => [
            ['from' => '6281234567', 'id' => 'wamid.X1', 'type' => 'text', 'text' => ['body' => 'Baik, sudah kami transfer']]]]]]]]];
        (new WebhookWaService())->terima('meta', $masuk);
        (new WebhookWaService())->terima('meta', $masuk);

        $p = WaPesan::where('arah', 'masuk')->get();
        $this->assertCount(1, $p);
        $this->assertSame($w->id, $p[0]->user_id);
        $this->assertSame('Baik, sudah kami transfer', $p[0]->isi);
    }

    public function test_tanda_tangan_webhook_dan_verifikasi_meta(): void
    {
        $body = '{"entry":[]}';
        $this->assertTrue(WebhookWaService::tandaSah($body, 'sha256='.hash_hmac('sha256', $body, 'rahasia'), 'rahasia'));
        $this->assertFalse(WebhookWaService::tandaSah($body, 'sha256='.hash_hmac('sha256', $body, 'palsu'), 'rahasia'));
        $this->assertFalse(WebhookWaService::tandaSah($body, null, 'rahasia'));
        $this->assertFalse(WebhookWaService::tandaSah($body, 'sha256='.hash_hmac('sha256', $body, ''), ''), 'secret kosong selalu ditolak');
        $this->assertSame('123', WebhookWaService::verifikasiMeta('subscribe', 'tok', '123', 'tok'));
        $this->assertNull(WebhookWaService::verifikasiMeta('subscribe', '', '123', ''));
    }

    public function test_nomor_diubah_ke_format_internasional(): void
    {
        $this->assertSame('6281234', HasilKirim::nomorInternasional('0812-34'));
        $this->assertSame('6281234', HasilKirim::nomorInternasional('+62 812 34'));
    }

    public function test_password_lama_myth_auth_diterima_sekali_lalu_diganti_hash_laravel(): void
    {
        $mythHash = password_hash(base64_encode(hash('sha384', 'rahasia123', true)), PASSWORD_DEFAULT);
        $u = $this->buatUser('Wali Lama');
        $u->update(['password' => AkunService::hash(AkunService::acak()), 'password_lama' => $mythHash, 'wajib_ganti_password' => false]);
        $akun = new AkunService();

        $this->assertFalse($akun->cocokkanPassword($u, 'salah'));
        $this->assertTrue($akun->cocokkanPassword($u, 'rahasia123'));
        $u->refresh();
        $this->assertNull($u->password_lama);
        $this->assertTrue(password_verify('rahasia123', $u->password));
        $this->assertFalse((bool) $u->wajib_ganti_password);

        $pendek = $this->buatUser('Wali Pendek');
        $pendek->update(['password' => 'x', 'password_lama' => password_hash(base64_encode(hash('sha384', '1234', true)), PASSWORD_DEFAULT)]);
        $this->assertTrue($akun->cocokkanPassword($pendek, '1234'));
        $this->assertTrue((bool) $pendek->fresh()->wajib_ganti_password, 'password lama < 8 karakter wajib diganti');
    }
}
