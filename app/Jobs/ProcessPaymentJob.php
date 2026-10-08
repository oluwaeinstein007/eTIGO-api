<?php

namespace App\Jobs;

use App\Models\Ride;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

    public function handle(PaymentService $paymentService): void
    {
        $ride = DB::transaction(function () {
            $ride = Ride::with('passenger')->lockForUpdate()->find($this->rideId);

            if (! $ride) {
                Log::warning('ProcessPaymentJob: ride not found', ['ride_id' => $this->rideId]);

                return null;
            }

            if ($ride->payment()->exists()) {
                Log::info('ProcessPaymentJob: payment already exists', ['ride_id' => $this->rideId]);

                return null;
            }

            return $ride;
        });

        if (! $ride) {
            return;
        }

        try {
            $payment = $paymentService->processRidePayment($ride);

            Log::info('Payment processed', [
                'ride_id' => $ride->id,
                'payment_id' => $payment->id,
                'method' => $payment->method->value,
                'status' => $payment->status->value,
            ]);
        } catch (\Throwable $e) {
            Log::error('Payment processing failed', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
