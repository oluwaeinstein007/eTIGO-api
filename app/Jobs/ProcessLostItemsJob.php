<?php

namespace App\Jobs;

use App\Services\LostItemService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Hourly Lost & Found sweep (LostItemService::processOverdue): silent
 * drivers and overdue returns go to support, undisputed returns resolve,
 * stale "not found" reports close and pending return fees are retried.
 */
class ProcessLostItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(LostItemService $service): void
    {
        $counts = $service->processOverdue();

        if (array_sum($counts) > 0) {
            Log::info('Lost & Found sweep', $counts);
        }
    }
}
