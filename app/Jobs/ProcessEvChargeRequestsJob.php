<?php

namespace App\Jobs;

use App\Services\EvChargeRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Holds a stall for each confirmed charge request whose slot has started,
 * and expires requests nobody reviewed before their day ended.
 */
class ProcessEvChargeRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(EvChargeRequestService $service): void
    {
        $result = $service->processDue();

        if ($result['started'] > 0 || $result['expired'] > 0) {
            Log::info('Processed EV charge requests.', $result);
        }
    }
}
