<?php

namespace App\Jobs;

use App\Models\MigrasiRun;
use App\Models\User;
use App\Services\Migrasi\MigrasiDataLama;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dijalankan dari tombol "Salin ulang semua data". ~95 ribu transaksi: berjalan di antrean
 * (driver database + cron `queue:work --stop-when-empty` di cPanel), layar memantau migrasi_run.
 */
class SinkronkanDataLama implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $runId, public int $adminId) {}

    public function handle(): void
    {
        (new MigrasiDataLama(config('khandaq.koneksi_lama'), config('khandaq.mode'), koneksiLoginLama: config('khandaq.koneksi_login_lama')))
            ->jalankan(User::findOrFail($this->adminId), MigrasiRun::findOrFail($this->runId));
    }
}
