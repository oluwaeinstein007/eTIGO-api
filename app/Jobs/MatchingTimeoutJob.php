<?php

namespace App\Jobs;

use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Services\DriverMatchingService;
use App\Services\RideStateMachine;
use App\Services\WalletPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatchingTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15];

    public function __construct(
        public readonly string $rideId,
    ) {}

    public function handle(
        RideStateMachine $stateMachine,
        DriverMatchingService $matchingService,
        WalletPaymentService $walletPaymentService,
    ): void {
        $ride = Ride::find($this->rideId);

        if (! $ride || $ride->status !== RideStatus::Searching) {
            return;
        }

        Log::info('Matching timed out — no driver found', [
            'ride_id' => $this->rideId,
        ]);

        DB::transaction(function () use ($ride, $stateMachine, $walletPaymentService) {
            $stateMachine->transitionTo(
                $ride,
                RideStatus::NoDriverFound,
                null,
                'system',
                ['reason' => 'matching_timeout'],
            );

            if ($ride->payment_method === PaymentMethod::Wallet) {
                $walletPaymentService->releaseHold($ride);
            }
        });

        $matchingService->cleanupRideCache($ride);
    }
}
