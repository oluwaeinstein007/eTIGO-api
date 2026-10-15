<?php

namespace App\Jobs;

use App\Services\SanctionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ends automatic L2 (off-app trip) suspensions once the configured
 * suspension period has elapsed; admin-imposed suspensions are untouched.
 */
class LiftExpiredComplianceSuspensionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SanctionService $sanctionService): void
    {
        $lifted = $sanctionService->liftExpiredSuspensions();

        if ($lifted > 0) {
            Log::info("Lifted {$lifted} expired compliance suspensions.");
        }
    }
}
