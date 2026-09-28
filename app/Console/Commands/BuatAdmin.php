<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AkunService;
use App\Services\PendaftaranService;
use Illuminate\Console\Command;

/**
 * Akun Admin pertama setelah instalasi (sebelumnya tidak ada yang bisa membuat akun lewat layar).
 * Password sementara dicetak sekali di terminal dan wajib diganti saat masuk pertama.
 */
class BuatAdmin extends Command
{
    protected $signature = 'khandaq:buat-admin {username} {--nama=Administrator} {--email=} {--telepon=}';

    protected $description = 'Buat akun Admin (atau reset password Admin yang sudah ada) dengan password sementara.';

    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $password = AkunService::acak();
        $user = User::firstOrNew(['username' => $username]);
        $baru = ! $user->exists;
        $user->fill([
            'name' => $user->name ?: (string) $this->option('nama'),
            'email' => $user->email ?: ($this->option('email') ?: "{$username}@khandaq.local"),
            'telepon' => $this->option('telepon') ? PendaftaranService::normalTelepon((string) $this->option('telepon')) : $user->telepon,
            'password' => AkunService::hash($password), 'password_lama' => null,
            'wajib_ganti_password' => true, 'aktif' => true,
        ])->save();
        $user->assignRole('admin');

        $this->info(($baru ? 'Admin dibuat' : 'Password Admin di-reset').": {$username}");
        $this->line("Password sementara: {$password}");
        $this->warn('Catat sekarang; password ini tidak ditampilkan lagi dan wajib diganti saat masuk pertama.');

        return self::SUCCESS;
    }
}
