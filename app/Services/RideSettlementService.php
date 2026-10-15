<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Driver;
use App\Models\Payment;
use App\Models\Ride;
use Illuminate\Support\Facades\Log;

/**
 * Applies the driver-side consequences of a ride payment: crediting earnings
 * net of commission and allocating fleet remittance. It runs when the payment
 * is first processed and again when a pending card or bank-transfer payment
 * is captured by webhook. Every step is idempotent per ride, so it is safe to
 * call repeatedly (e.g. on job retries).
 */
class RideSettlementService
{
    public function __construct(
        private DriverEarningsService $earningsService,
        private FleetRemittanceService $fleetService,
    ) {}

    public function applyPayment(Ride $ride, Payment $payment): void
    {
        if (! $this->isSettled($payment)) {
            return;
        }

        if (! Driver::where('user_id', $ride->driver_id)->exists()) {
            Log::error('Ride payment settled but the driver has no driver profile; earnings not credited', [
                'ride_id' => $ride->id,
                'driver_user_id' => $ride->driver_id,
            ]);

            return;
        }

        // Wallet settlement credits the driver inside the same ledger journal
        // that debits the passenger, so only external payments are credited here.
        if ($payment->method !== PaymentMethod::Wallet) {
            $this->earningsService->creditRideEarnings($ride, (int) round((float) $payment->amount * 100));
        }

        $this->recordFleetRemittance($ride);
    }

    private function isSettled(Payment $payment): bool
    {
        // The driver holds cash from the moment the trip ends; other methods
        // count once the money has actually been captured.
        return $payment->method === PaymentMethod::Cash
            || in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::Settled], true);
    }

    private function recordFleetRemittance(Ride $ride): void
    {
        $driver = Driver::where('user_id', $ride->driver_id)->first();
        $agreement = $driver?->isFleetVehicle() ? $driver->activeFleetAgreement : null;

        if (! $agreement) {
            return;
        }

        $fareAmount = (float) ($ride->final_fare_amount ?? $ride->fare_estimate_amount);

        $this->fleetService->recordRideRemittance($agreement, $fareAmount, $ride);

        Log::info('Fleet remittance recorded', [
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'agreement_id' => $agreement->id,
            'fare_amount' => $fareAmount,
        ]);
    }
}
