<?php

use App\Jobs\CaptureOperationsSnapshotJob;
use App\Jobs\CheckSilentDriversJob;
use App\Jobs\DailyReconciliationJob;
use App\Jobs\DailyRemittanceSettlementJob;
use App\Jobs\ExpireAbandonedTopupsJob;
use App\Jobs\ExpireEvReservationsJob;
use App\Jobs\ExpireStaleHoldsJob;
use App\Jobs\LiftExpiredComplianceSuspensionsJob;
use App\Jobs\NegativeDriverBalanceReportJob;
use App\Jobs\ProcessDisputeTargetsJob;
use App\Jobs\ProcessDriverShiftsJob;
use App\Jobs\ProcessLostItemsJob;
use App\Jobs\ProcessScheduledRidesJob;
use App\Jobs\ProcessVehicleMaintenanceJob;
use App\Jobs\ProcessWeeklyAutoPayoutJob;
use App\Jobs\ReconcilePayoutsJob;
use App\Jobs\RemittanceShortfallAlertJob;
use App\Jobs\SettleDriverEarningsJob;
use App\Jobs\StuckTransactionSweeperJob;
use App\Models\Notification;
use App\Models\OperationsSnapshot;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('vehicles:check-maintenance', function () {
    ProcessVehicleMaintenanceJob::dispatchSync();
    $this->info('Vehicle maintenance checked: items updated, reminders and the operations digest sent.');
})->purpose('Run the daily vehicle maintenance check now (items, reminders, expired-papers block)');

Schedule::job(new ExpireStaleHoldsJob)->hourly();
Schedule::job(new ExpireAbandonedTopupsJob)->everyFifteenMinutes();
Schedule::job(new SettleDriverEarningsJob)->hourly()->withoutOverlapping();
Schedule::job(new StuckTransactionSweeperJob)->everyThirtyMinutes()->withoutOverlapping();
Schedule::job(new ReconcilePayoutsJob)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new DailyReconciliationJob)->dailyAt('02:00');
Schedule::command('ledger:check')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('pricing:check-coverage')->dailyAt('07:00')->timezone('Africa/Lagos');
Schedule::job(new ExpireEvReservationsJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new CheckSilentDriversJob)->everyMinute()->withoutOverlapping();
// Scheduled rides: start matching shortly before pickup and send reminders.
Schedule::job(new ProcessScheduledRidesJob)->everyMinute()->withoutOverlapping();
Schedule::job(new LiftExpiredComplianceSuspensionsJob)->hourly()->withoutOverlapping();
// Lost & Found: chase silent drivers, resolve undisputed returns, close stale reports.
Schedule::job(new ProcessLostItemsJob)->hourly()->withoutOverlapping();
// Disputes: move tier 1 cases that miss a target up, alert on Safety and tier 2 misses.
Schedule::job(new ProcessDisputeTargetsJob)->everyFifteenMinutes()->withoutOverlapping();
// Fleet shifts: missed shifts, offer cut-off notices, late handovers, shift ends.
Schedule::job(new ProcessDriverShiftsJob)->everyFiveMinutes()->withoutOverlapping();
// Vehicle maintenance: due dates, reminders, the expired-papers block.
Schedule::job(new ProcessVehicleMaintenanceJob)->dailyAt('06:00')->timezone('Africa/Lagos')->withoutOverlapping();
// Admin overview: point-in-time counts for the "vs. this time yesterday" figures.
Schedule::job(new CaptureOperationsSnapshotJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [Notification::class, OperationsSnapshot::class]])->daily();
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
