<?php

namespace App\Jobs;

use App\Services\ScheduledRideService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Starts matching for one scheduled ride whose dispatch time has come
 * (BE2-SCH-04). Queued by ProcessScheduledRidesJob; safe to run twice.
 */
class ScheduledRideDispatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    // The sweep queues the booking again every minute until it is handled.
    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $scheduledRideId,
    ) {}

    public function uniqueId(): string
    {
        return $this->scheduledRideId;
    }

    public function handle(ScheduledRideService $service): void
    {
        $service->dispatch($this->scheduledRideId);
    }
}
