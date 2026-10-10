<?php

use App\Jobs\DailyReconciliationJob;
use App\Jobs\DailyRemittanceSettlementJob;
use App\Jobs\ExpireAbandonedTopupsJob;
use App\Jobs\ExpireStaleHoldsJob;
use App\Jobs\NegativeDriverBalanceReportJob;
use App\Jobs\RemittanceShortfallAlertJob;
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
Schedule::job(new SettleDriverEarningsJob)->hourly()->withoutOverlapping();
Schedule::job(new StuckTransactionSweeperJob)->everyThirtyMinutes()->withoutOverlapping();
Schedule::job(new DailyReconciliationJob)->dailyAt('02:00');
Schedule::job(new NegativeDriverBalanceReportJob)->weeklyOn(1, '08:00');

$resetTime = config('fleet.daily_reset_time', '04:00');
$settlementDate = now('Africa/Lagos')->subDay()->toDateString();
Schedule::job(new DailyRemittanceSettlementJob($settlementDate))
    ->dailyAt($resetTime)
    ->timezone('Africa/Lagos')
    ->withoutOverlapping()
    ->then(fn () => RemittanceShortfallAlertJob::dispatch());
