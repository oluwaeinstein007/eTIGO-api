<?php

namespace App\Jobs;

use App\Enums\RideStatus;
use App\Models\Ride;
use App\Services\DriverMatchingService;
use App\Services\RideStateMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MatchingTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $rideId,
    ) {}

    public function handle(
        RideStateMachine $stateMachine,
        DriverMatchingService $matchingService,
    ): void {
        $ride = Ride::find($this->rideId);

        if (! $ride || $ride->status !== RideStatus::Searching) {
            return;
        }

        Log::info('Matching timed out — no driver found', [
            'ride_id' => $this->rideId,
        ]);

        $stateMachine->transitionTo(
            $ride,
            RideStatus::NoDriverFound,
            null,
            'system',
            ['reason' => 'matching_timeout'],
        );

        $matchingService->cleanupRideCache($ride);
    }
}
