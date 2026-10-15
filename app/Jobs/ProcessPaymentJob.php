<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\Ride;
use App\Services\DriverEarningsService;
use App\Services\FleetRemittanceService;
use App\Services\PaymentService;
use App\Services\RideService;
use App\Services\RideSettlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaymentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $rideId,
    ) {}

    public function uniqueId(): string
    {
        return $this->rideId;
    }

    /**
     * The fleet and earnings services are resolved through the settlement
     * service; the parameters are kept for callers that invoke handle() directly.
     */
    public function handle(
        PaymentService $paymentService,
        FleetRemittanceService $fleetService,
        DriverEarningsService $earningsService,
    ): void {
        $ride = Ride::with('passenger')->find($this->rideId);

        if (! $ride) {
            Log::warning('ProcessPaymentJob: ride not found', ['ride_id' => $this->rideId]);

            return;
        }

        $this->ensureFinalFare($ride);

        $payment = Payment::where('ride_id', $ride->id)->first();

        if ($payment) {
            Log::info('ProcessPaymentJob: payment already exists', ['ride_id' => $this->rideId]);
        } else {
            $payment = $paymentService->processRidePayment($ride);

            Log::info('Payment processed', [
                'ride_id' => $ride->id,
                'payment_id' => $payment->id,
                'method' => $payment->method->value,
                'status' => $payment->status->value,
            ]);
        }

        // Idempotent per ride: a retry after a partial failure completes the
        // earnings credit and fleet remittance without charging again.
        app(RideSettlementService::class)->applyPayment($ride->fresh(), $payment->fresh());
    }

    /**
     * The ride is charged its final fare. If the fare could not be computed at
     * completion (e.g. the maps provider was down) it is retried here, and as a
     * last resort the booking estimate the passenger agreed to is charged and
     * recorded as the final fare so the ride and payment never disagree.
     */
    private function ensureFinalFare(Ride $ride): void
    {
        if ($ride->final_fare_amount !== null) {
            return;
        }

        try {
            app(RideService::class)->calculateFinalFare($ride);
        } catch (\Throwable $e) {
            Log::error('Final fare unavailable at payment time; charging the booking estimate', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
            ]);

            $ride->update([
                'final_fare_amount' => $ride->fare_estimate_amount,
                'pricing_snapshot' => [
                    ...($ride->pricing_snapshot ?? []),
                    'final_fare_fallback' => 'booking_estimate',
                ],
            ]);
        }

        $ride->refresh();
    }
}
