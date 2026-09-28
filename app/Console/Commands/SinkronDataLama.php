<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Migrasi\MigrasiDataLama;
use Illuminate\Console\Command;

/** Versi baris perintah dari tombol "Salin ulang semua data" (mis. dijalankan malam hari lewat cron). */
class SinkronDataLama extends Command
{
    protected $signature = 'khandaq:sinkron-data-lama {--admin= : username admin yang menjalankan} {--force : lewati konfirmasi}';

    protected $description = 'Salin ulang semua data dari database aplikasi lama ke tabel baru (mengganti salinan sebelumnya).';

    public function handle(): int
    {
        $admin = User::where('username', $this->option('admin'))->firstOrFail();
        if (! $this->option('force') && ! $this->confirm('Semua data di aplikasi baru (kecuali akun staf & pengaturan peran) akan diganti. Lanjut?')) {
            return self::FAILURE;
        }
        $run = (new MigrasiDataLama(config('khandaq.koneksi_lama'), config('khandaq.mode'), koneksiLoginLama: config('khandaq.koneksi_login_lama')))->jalankan($admin);
        foreach ($run->ringkasan as $tabel => $r) {
            $this->line(sprintf('%-28s %7d -> %7d  (dilewati %d)', $tabel, $r['sumber'], $r['masuk'], $r['dilewati']));
        }
        $rek = $run->rekonsiliasi;
        $this->info("Saldo sama: {$rek['sama']}/{$rek['santri']} santri. Total lama {$rek['total_saldo_lama']}, baru {$rek['total_saldo_baru']}.");

        return self::SUCCESS;
    }
}
