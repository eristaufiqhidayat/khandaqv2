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
        // Batas bawaan PHP di banyak server hanya 128 MB. Sinkronisasi membaca ~95 ribu transaksi.
        $batas = (string) ini_get('memory_limit');
        if ($batas !== '-1' && $this->keByte($batas) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
        (new MigrasiDataLama(config('khandaq.koneksi_lama'), config('khandaq.mode'), koneksiLoginLama: config('khandaq.koneksi_login_lama')))
            ->jalankan(User::findOrFail($this->adminId), MigrasiRun::findOrFail($this->runId));
    }

    /** Dipanggil Laravel bila job gagal (termasuk setelah proses mati dan job dicoba ulang melewati batas). */
    public function failed(?\Throwable $e): void
    {
        MigrasiRun::find($this->runId)?->batalkan('Gagal: '.mb_substr($e?->getMessage() ?? 'tidak diketahui', 0, 400));
    }

    private function keByte(string $nilai): int
    {
        $n = (int) $nilai;

        return match (strtoupper(substr(trim($nilai), -1))) {
            'G' => $n * 1024 ** 3, 'M' => $n * 1024 ** 2, 'K' => $n * 1024, default => $n,
        };
    }
}
