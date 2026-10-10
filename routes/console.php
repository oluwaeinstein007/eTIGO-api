<?php

use App\Jobs\ExpireAbandonedTopupsJob;
use App\Jobs\ExpireStaleHoldsJob;
use App\Jobs\SettleDriverEarningsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ExpireStaleHoldsJob)->hourly();
Schedule::job(new ExpireAbandonedTopupsJob)->everyFifteenMinutes();
Schedule::job(new SettleDriverEarningsJob)->hourly();
