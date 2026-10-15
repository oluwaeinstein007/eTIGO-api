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

    /**
     * The search attempt (1 = initial request, 2+ = passenger retries) this
     * timer belongs to. Null applies the timeout to whatever search is running.
     * Declared with a default so jobs queued before this property existed
     * still unserialize cleanly.
     */
    public ?int $searchAttempt = null;

    public function __construct(
        public readonly string $rideId,
        ?int $searchAttempt = null,
    ) {
        $this->searchAttempt = $searchAttempt;
    }

    public function handle(
        RideStateMachine $stateMachine,
        DriverMatchingService $matchingService,
        WalletPaymentService $walletPaymentService,
    ): void {
        $ride = DB::transaction(function () use ($stateMachine, $walletPaymentService) {
            $ride = Ride::lockForUpdate()->find($this->rideId);

            if (! $ride || $ride->status !== RideStatus::Searching) {
                return null;
            }

            if ($this->searchAttempt !== null && $ride->searchAttemptCount() !== $this->searchAttempt) {
                // Timer from an earlier search; the passenger has since retried.
                return null;
            }

            Log::info('Matching timed out — no driver found', [
                'ride_id' => $this->rideId,
            ]);

            $ride = $stateMachine->transitionTo(
                $ride,
                RideStatus::NoDriverFound,
                null,
                'system',
                ['reason' => 'matching_timeout'],
            );

            if ($ride->payment_method === PaymentMethod::Wallet) {
                $walletPaymentService->releaseHold($ride);
            }

            return $ride;
        });

        if ($ride) {
            $matchingService->cleanupRideCache($ride);
        }
    }
}
