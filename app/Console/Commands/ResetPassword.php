<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AkunService;
use Illuminate\Console\Command;

/** Reset password dari server bila WhatsApp belum aktif. Password sementara dicetak sekali dan wajib diganti. */
class ResetPassword extends Command
{
    protected $signature = 'khandaq:reset-password {username}';

    protected $description = 'Buat password sementara untuk akun (staf atau wali) dan cetak di terminal.';

    public function handle(): int
    {
        $u = User::where('username', $this->argument('username'))->first();
        if (! $u) {
            $this->error('Username tidak ditemukan.');

            return self::FAILURE;
        }
        $pw = AkunService::acak();
        $u->update(['password' => AkunService::hash($pw), 'password_lama' => null, 'wajib_ganti_password' => true, 'aktif' => true]);
        $this->info("Password sementara {$u->name} ({$u->username}): {$pw}");
        $this->warn('Sampaikan langsung ke pemilik akun; wajib diganti saat masuk pertama.');

        return self::SUCCESS;
    }
}
