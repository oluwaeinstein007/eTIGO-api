<?php

namespace App\Jobs;

use App\Services\DisputeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 15-minute dispute sweep (DisputeService::processTargets): tier 1 cases
 * that miss a response target move to tier 2; Safety and tier 2 misses
 * alert the super admins.
 */
class ProcessDisputeTargetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DisputeService $service): void
    {
        $counts = $service->processTargets();

        if (array_sum($counts) > 0) {
            Log::info('Dispute target sweep', $counts);
        }
    }
}
