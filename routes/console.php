<?php

use App\Jobs\DailyReconciliationJob;
use App\Jobs\ExpireAbandonedTopupsJob;
use App\Jobs\ExpireStaleHoldsJob;
use App\Jobs\NegativeDriverBalanceReportJob;
use App\Jobs\SettleDriverEarningsJob;
use App\Jobs\StuckTransactionSweeperJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ExpireStaleHoldsJob)->hourly();
Schedule::job(new ExpireAbandonedTopupsJob)->everyFifteenMinutes();
Schedule::job(new SettleDriverEarningsJob)->hourly();
Schedule::job(new StuckTransactionSweeperJob)->everyThirtyMinutes();
Schedule::job(new DailyReconciliationJob)->dailyAt('02:00');
Schedule::job(new NegativeDriverBalanceReportJob)->weeklyOn(1, '08:00');
