<?php

namespace App\Console\Commands;

use App\Services\TagihanGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BuatTagihanBulanan extends Command
{
    protected $signature = 'khandaq:tagihan-bulanan {--bulan= : YYYY-MM, default bulan ini}';

    protected $description = 'Membuat tagihan SPP, laundry, dan kesehatan bulan berjalan untuk semua santri aktif (idempoten).';

    public function handle(TagihanGenerator $generator): int
    {
        $bulan = $this->option('bulan') ? CarbonImmutable::parse($this->option('bulan').'-01') : CarbonImmutable::now();
        $n = $generator->bulanan($bulan);
        $this->info("{$n} tagihan dibuat untuk {$bulan->format('Y-m')}.");

        return self::SUCCESS;
    }
}
