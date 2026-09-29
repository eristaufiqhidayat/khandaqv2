<?php

namespace App\Console\Commands;

use App\Services\NotifikasiEmailBsi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Baca email notifikasi transaksi BSI dan catat ke mutasi bank.
 *
 * Tiga cara:
 *   1. Folder email (cron):  khandaq:bsi-email --maildir=/home/USER/mail/lembaharafah.com/bsi1
 *      (atau isi BSI_EMAIL_MAILDIR di .env; penjadwal menjalankannya tiap 5 menit)
 *   2. Pipe cPanel:          |/path/laravel/bin/bsi-email   (memanggil khandaq:bsi-email --stdin)
 *   3. Berkas:               khandaq:bsi-email contoh.eml lain.eml
 *
 * Aman diulang: nomor transaksi yang sudah tercatat dilewati.
 */
class NotifikasiBsi extends Command
{
    protected $signature = 'khandaq:bsi-email
        {berkas?* : berkas .eml}
        {--maildir= : folder Maildir akun email (berisi new/ dan cur/)}
        {--hari=3 : hanya email yang diterima N hari terakhir (mode maildir)}
        {--stdin : baca satu email dari STDIN (pipe cPanel), tanpa keluaran}';

    protected $description = 'Catat notifikasi email transaksi BSI ke mutasi bank dan cocokkan dengan setoran wali';

    public function handle(NotifikasiEmailBsi $notifikasi): int
    {
        if ($this->option('stdin')) {
            // Pipe cPanel: keluaran apa pun akan dipantulkan (bounce) ke pengirim, jadi hanya dicatat di log.
            try {
                $h = $notifikasi->proses((string) stream_get_contents(STDIN));
                Log::info('Notifikasi BSI (pipe): '.$h['pesan']);
            } catch (\Throwable $e) {
                Log::error('Notifikasi BSI (pipe) gagal: '.$e->getMessage());
            }

            return self::SUCCESS;
        }

        $berkas = $this->argument('berkas');
        if (! $berkas) {
            $dir = rtrim((string) ($this->option('maildir') ?: config('khandaq.bsi.email_maildir')), '/');
            if ($dir === '') {
                $this->error('Sebutkan berkas .eml, --maildir, --stdin, atau isi BSI_EMAIL_MAILDIR di .env.');

                return self::INVALID;
            }
            if (! is_dir("{$dir}/new") && ! is_dir("{$dir}/cur")) {
                $this->error("{$dir} bukan folder Maildir (tidak ada new/ atau cur/).");

                return self::FAILURE;
            }
            $batas = time() - max(1, (int) $this->option('hari')) * 86400;
            foreach (['new', 'cur'] as $sub) {
                foreach (glob("{$dir}/{$sub}/*") ?: [] as $f) {
                    if (is_file($f) && filemtime($f) >= $batas) {
                        $berkas[] = $f;
                    }
                }
            }
        }

        $jumlah = [NotifikasiEmailBsi::BARU => 0, NotifikasiEmailBsi::DUPLIKAT => 0, NotifikasiEmailBsi::DITOLAK => 0];
        foreach ($berkas as $f) {
            $raw = @file_get_contents($f);
            if ($raw === false || ! str_contains($raw, 'bankbsi.co.id')) {
                continue; // email lain di kotak masuk yang sama
            }
            $h = $notifikasi->proses($raw);
            $jumlah[$h['hasil']]++;
            if ($h['hasil'] !== NotifikasiEmailBsi::DUPLIKAT) {
                $this->line(($h['hasil'] === NotifikasiEmailBsi::BARU ? '<info>+</info> ' : '<comment>!</comment> ').$h['pesan'].($this->output->isVerbose() ? '  ['.basename($f).']' : ''));
            }
        }
        $this->info("Selesai: {$jumlah['baru']} baru, {$jumlah['duplikat']} sudah tercatat, {$jumlah['ditolak']} ditolak.");

        return self::SUCCESS;
    }
}
