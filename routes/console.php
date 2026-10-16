<?php

use App\Jobs\DailyReconciliationJob;
use App\Jobs\DailyRemittanceSettlementJob;
use App\Jobs\ExpireAbandonedTopupsJob;
use App\Jobs\ExpireEvReservationsJob;
use App\Jobs\ExpireStaleHoldsJob;
use App\Jobs\LiftExpiredComplianceSuspensionsJob;
use App\Jobs\NegativeDriverBalanceReportJob;
use App\Jobs\ProcessScheduledRidesJob;
use App\Jobs\ProcessWeeklyAutoPayoutJob;
use App\Jobs\ReconcilePayoutsJob;
use App\Jobs\RemittanceShortfallAlertJob;
use App\Jobs\SettleDriverEarningsJob;
use App\Jobs\StuckTransactionSweeperJob;
use App\Models\Notification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ExpireStaleHoldsJob)->hourly();
Schedule::job(new ExpireAbandonedTopupsJob)->everyFifteenMinutes();
Schedule::job(new SettleDriverEarningsJob)->hourly()->withoutOverlapping();
Schedule::job(new StuckTransactionSweeperJob)->everyThirtyMinutes()->withoutOverlapping();
Schedule::job(new ReconcilePayoutsJob)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new DailyReconciliationJob)->dailyAt('02:00');
Schedule::command('ledger:check')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('pricing:check-coverage')->dailyAt('07:00')->timezone('Africa/Lagos');
Schedule::job(new ExpireEvReservationsJob)->everyFiveMinutes()->withoutOverlapping();
// Scheduled rides: start matching shortly before pickup and send reminders.
Schedule::job(new ProcessScheduledRidesJob)->everyMinute()->withoutOverlapping();
Schedule::job(new LiftExpiredComplianceSuspensionsJob)->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [Notification::class]])->daily();
Schedule::command('demo:simulate-drivers')
    ->everyMinute()
    ->environments(['local', 'staging'])
    ->withoutOverlapping();
Schedule::job(new NegativeDriverBalanceReportJob)->weeklyOn(1, '08:00');
Schedule::job(new ProcessWeeklyAutoPayoutJob)
    ->weeklyOn(1, '06:00')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping();

// Shortfall flags are computed from the streaks that settlement updates, so
// the alert job is chained to run only after settlement has finished.
Schedule::call(fn () => Bus::chain([
    new DailyRemittanceSettlementJob(now('Africa/Lagos')->subDay()->toDateString()),
    new RemittanceShortfallAlertJob,
])->dispatch())
    ->name('fleet-daily-remittance-settlement')
    ->dailyAt(config('fleet.daily_reset_time', '04:00'))
    ->timezone('Africa/Lagos')
    ->withoutOverlapping();
