<?php

use Illuminate\Support\Facades\Schedule;

// Di cPanel cukup satu cron: * * * * * cd /path/app && php artisan schedule:run >> /dev/null 2>&1

// Tagihan bulanan terbit tiap tanggal 1 pukul 00:10 (jatuh tempo akhir bulan).
Schedule::command('khandaq:tagihan-bulanan')->monthlyOn(1, '00:10')->withoutOverlapping();

// Pengingat H-3 & tahapan tunggakan, setiap pagi.
Schedule::command('khandaq:peringatan')->dailyAt('07:00')->withoutOverlapping();
