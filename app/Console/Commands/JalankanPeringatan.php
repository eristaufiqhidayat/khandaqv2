<?php

namespace App\Console\Commands;

use App\Services\PeringatanService;
use Illuminate\Console\Command;

class JalankanPeringatan extends Command
{
    protected $signature = 'khandaq:peringatan';

    protected $description = 'Pengingat H-3 dan tahapan tunggakan 1/2/3 bulan (idempoten per bulan).';

    public function handle(PeringatanService $svc): int
    {
        foreach ($svc->jalankan() as $tahap => $jumlah) {
            $this->line("{$tahap}: {$jumlah}");
        }

        return self::SUCCESS;
    }
}
