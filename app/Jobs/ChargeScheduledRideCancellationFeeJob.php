<?php

namespace App\Jobs;

use App\Services\ScheduledRideCancellationFeeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Collects a scheduled ride's late-cancellation fee from the passenger's
 * wallet or saved card and pays the reserved driver. One attempt: a card
 * charge is never retried automatically.
 */
class ChargeScheduledRideCancellationFeeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $scheduledRideId,
    ) {}

    public function uniqueId(): string
    {
        return $this->scheduledRideId;
    }

    public function handle(ScheduledRideCancellationFeeService $service): void
    {
        $service->charge($this->scheduledRideId);
    }
}
