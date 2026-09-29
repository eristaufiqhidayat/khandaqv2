<?php

namespace Tests\Feature;

use App\Enums\StatusBankMutasi;
use App\Enums\StatusMutasi;
use App\Models\BankMutasi;
use App\Models\Rekening;
use App\Services\BankMutasiMatcher;
use App\Services\NotifikasiEmailBsi;
use Illuminate\Support\Facades\Storage;
use Tests\KhandaqTestCase;

class NotifikasiEmailBsiTest extends KhandaqTestCase
{
    /** Kunci publik DKIM asli bankbsi.co.id (selector "default"), agar uji tidak bergantung DNS. */
    private const KUNCI_BSI = 'v=DKIM1;k=rsa;p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDgVcnl4aeOsTCZGm+6ibKmeoogx3jvY6Jo2LEL6ZgFwwrQ+wmQoQqwUXqqjBYH96YuQVDzhJaKjQ6HGVCMNg4MdrpfoxJvwe8qWLSJWFGY+AHrhRdZ5WapnnXCl9QhSlLAUYXygH7LunECTyCz7w8c7RzaUSAbjrEpDWV+gJm6GQIDAQAB';

    private \OpenSSLAsymmetricKey $kunciUji;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Rekening::kode('BSI')->update(['nomor' => '7123453330']);
        $this->kunciUji = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    private function layanan(): NotifikasiEmailBsi
    {
        $uji = "v=DKIM1;k=rsa;p=".preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details($this->kunciUji)['key']);

        return new NotifikasiEmailBsi(app(BankMutasiMatcher::class), fn (string $host) => match ($host) {
            'default._domainkey.bankbsi.co.id' => self::KUNCI_BSI,
            'uji._domainkey.bankbsi.co.id' => $uji,
            default => null,
        });
    }

    /** Email notifikasi bertanda tangan DKIM (simple/simple) dengan kunci uji, meniru format BSI. */
    private function email(string $arah, int $nominal, string $ref, string $tgl = '05 FEB 2026 08:15', ?\OpenSSLAsymmetricKey $kunci = null): string
    {
        $tanda = $arah === 'Debit' ? '-' : '';
        $body = "Berikut Notifikasi untuk Anda : {$arah}<br>Nomor Rekening : XXXXXX3330<br>Nilai Transaksi : IDR{$tanda}".number_format($nominal, 2)
            ."<br>Tanggal Transaksi : {$tgl}<br>Nomor Transaksi : {$ref}<br><br>Jika transaksi tidak anda kenal,Hub Bank Syariah Indonesia Call 14040\r\n";
        $h = "Date: Thu, 5 Feb 2026 08:15:10 +0700 (WIB)\r\nFrom: BSICenter@bankbsi.co.id\r\nTo: BSI1@LEMBAHARAFAH.COM\r\n";
        $sig = 'DKIM-Signature: v=1; a=rsa-sha256; c=simple/simple; d=bankbsi.co.id; s=uji; h=Date:From:To; bh='
            .base64_encode(hash('sha256', $body, true)).'; b=';
        openssl_sign($h.$sig, $b, $kunci ?? $this->kunciUji, OPENSSL_ALGO_SHA256);

        return "Return-Path: <BSICenter@bankbsi.co.id>\r\n{$sig}".base64_encode($b)."\r\n{$h}Subject: Notifikasi{$arah}\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: text/html; charset=us-ascii\r\nContent-Transfer-Encoding: 7bit\r\n\r\n{$body}";
    }

    public function test_email_asli_bsi_lolos_dkim_dan_tercatat_sebagai_debet(): void
    {
        $raw = file_get_contents(base_path('tests/fixtures/bsi-notifikasi-debit.eml'));
        $n = $this->layanan()->urai($raw);
        $this->assertSame(['debit', '3330', 280_000, 'FT262590CWFJ'], [$n['arah'], $n['rekening'], $n['nominal'], $n['referensi']]);
        $this->assertSame('2026-09-16 07:37', $n['tanggal']->format('Y-m-d H:i'));

        $h = $this->layanan()->proses($raw);
        $this->assertSame(NotifikasiEmailBsi::BARU, $h['hasil'], $h['pesan']);
        $this->assertSame(280_000, $h['mutasi']->debet);
        $this->assertSame(StatusBankMutasi::Diabaikan, $h['mutasi']->status, 'debet bukan setoran santri');
        Storage::disk('local')->assertExists('bsi-email/2026/09/FT262590CWFJ.eml');

        $this->assertSame(NotifikasiEmailBsi::DUPLIKAT, $this->layanan()->proses($raw)['hasil']);
    }

    public function test_email_asli_yang_isinya_diubah_ditolak(): void
    {
        $raw = str_replace('IDR-280,000.00', 'IDR-2,800,000.00', file_get_contents(base_path('tests/fixtures/bsi-notifikasi-debit.eml')));
        $this->assertSame(NotifikasiEmailBsi::DITOLAK, $this->layanan()->proses($raw)['hasil']);
        $this->assertSame(0, BankMutasi::count());
    }

    public function test_kredit_mencocokkan_laporan_transfer_wali_secara_otomatis(): void
    {
        $a = $this->santri('3 PUTRA', '123');
        $this->tabungan()->catatSetoran($a, 2_315_123, $this->tgl('2026-02-04 10:00'), true, $this->wali($a));

        $h = $this->layanan()->proses($this->email('Kredit', 2_315_123, 'FT26036AAAA'));
        $this->assertSame(NotifikasiEmailBsi::BARU, $h['hasil'], $h['pesan']);
        $this->assertSame(StatusBankMutasi::Cocok, $h['mutasi']->status);
        $this->assertSame(StatusMutasi::Terverifikasi, $a->mutasi()->first()->status);
        $this->assertSame(2_315_123 - 15_000, $a->saldo());
    }

    public function test_kredit_tanpa_laporan_masuk_antrian_tinjau_dengan_santri_terdeteksi(): void
    {
        $b = $this->santri('2 PUTRA', '456');
        $h = $this->layanan()->proses($this->email('Kredit', 1_500_456, 'FT26036BBBB'));
        $this->assertSame(StatusBankMutasi::Ditinjau, $h['mutasi']->status);
        $this->assertSame($b->id, $h['mutasi']->santri_id_terdeteksi);
    }

    public function test_email_palsu_tidak_bisa_memverifikasi_setoran(): void
    {
        $a = $this->santri('3 PUTRA', '123');
        $this->tabungan()->catatSetoran($a, 2_315_123, $this->tgl('2026-02-04 10:00'), true, $this->wali($a));
        $palsu = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $this->assertSame(NotifikasiEmailBsi::DITOLAK, $this->layanan()->proses($this->email('Kredit', 2_315_123, 'FT26036CCCC', kunci: $palsu))['hasil']);
        $tanpaTtd = preg_replace('/^DKIM-Signature:.*\r\n/m', '', $this->email('Kredit', 2_315_123, 'FT26036DDDD'));
        $this->assertSame(NotifikasiEmailBsi::DITOLAK, $this->layanan()->proses($tanpaTtd)['hasil']);
        $this->assertSame(0, BankMutasi::count());
        $this->assertSame(StatusMutasi::Pending, $a->mutasi()->first()->status);
    }

    public function test_rekening_belum_diisi_ditolak_dengan_pesan_jelas(): void
    {
        Rekening::kode('BSI')->update(['nomor' => null]);
        $h = $this->layanan()->proses($this->email('Kredit', 100_000, 'FT26036EEEE'));
        $this->assertSame(NotifikasiEmailBsi::DITOLAK, $h['hasil']);
        $this->assertStringContainsString('3330 belum terdaftar', $h['pesan']);
    }

    public function test_perintah_membaca_maildir_dan_aman_diulang(): void
    {
        $this->app->instance(NotifikasiEmailBsi::class, $this->layanan());
        $dir = sys_get_temp_dir().'/maildir-'.uniqid();
        mkdir("$dir/new", 0777, true);
        mkdir("$dir/cur");
        file_put_contents("$dir/new/1.eml", $this->email('Kredit', 500_000, 'FT26036FFFF'));
        copy(base_path('tests/fixtures/bsi-notifikasi-debit.eml'), "$dir/cur/2.eml");
        file_put_contents("$dir/cur/3.eml", "From: teman@contoh.id\r\nSubject: Halo\r\n\r\nApa kabar");

        $this->artisan('khandaq:bsi-email', ['--maildir' => $dir])->expectsOutputToContain('2 baru, 0 sudah tercatat, 0 ditolak')->assertSuccessful();
        $this->artisan('khandaq:bsi-email', ['--maildir' => $dir])->expectsOutputToContain('0 baru, 2 sudah tercatat')->assertSuccessful();
        $this->assertSame(2, BankMutasi::count());
    }

    public function test_halaman_impor_menampilkan_notifikasi_email(): void
    {
        $this->layanan()->proses(file_get_contents(base_path('tests/fixtures/bsi-notifikasi-debit.eml')));
        $keu = \App\Models\User::create(['name' => 'Keu', 'username' => 'keu', 'email' => 'keu@test.local',
            'password' => \App\Services\AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole('keuangan');
        $this->actingAs($keu)->get(route('bank.index'))->assertOk()
            ->assertSee('Notifikasi email BSI')->assertSee('FT262590CWFJ')->assertSee('Rp280.000')->assertSee('Bukan setoran');
    }
}
