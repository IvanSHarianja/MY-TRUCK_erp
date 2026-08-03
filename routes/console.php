<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// GAP-05: Depresiasi bulanan auto-run tanggal 1, jam 02:00.
// Default command tanpa flag = bulan lalu, semua company.
Schedule::command('depreciation:run')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping()
    ->runInBackground();
