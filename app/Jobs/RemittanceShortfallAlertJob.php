<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\FleetAgreement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RemittanceShortfallAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $warningDays = config('fleet.shortfall_warning_days', 3);
        $reviewDays = config('fleet.shortfall_review_days', 7);
        $escalationDays = config('fleet.shortfall_escalation_days', 14);

        $agreements = FleetAgreement::active()
            ->where('shortfall_streak_days', '>=', $warningDays)
            ->with('driver.user')
            ->get();

        foreach ($agreements as $agreement) {
            $streak = $agreement->shortfall_streak_days;
            $currentFlag = $agreement->shortfall_flag;

            $newFlag = match (true) {
                $streak >= $escalationDays => 'escalated',
                $streak >= $reviewDays => 'review',
                $streak >= $warningDays => 'warning',
                default => null,
            };

            if ($newFlag === $currentFlag) {
                continue;
            }

            $agreement->update([
                'shortfall_flag' => $newFlag,
                'shortfall_flagged_at' => now(),
            ]);

            AuditLog::record($agreement, "shortfall.{$newFlag}", null, null, [
                'streak_days' => $streak,
                'driver_id' => $agreement->driver_id,
                'previous_flag' => $currentFlag,
            ]);

            Log::info('Shortfall escalation updated', [
                'agreement_id' => $agreement->id,
                'driver_id' => $agreement->driver_id,
                'streak_days' => $streak,
                'flag' => $newFlag,
            ]);
        }
    }
}
