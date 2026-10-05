<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Models\AuditLog;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RideService
{
    public function __construct(
        private RideStateMachine $stateMachine,
        private RidePinService $pinService,
        private FareEstimationService $fareEstimationService,
        private MapsGateway $mapsGateway,
    ) {}

    /**
     * @return array{ride: Ride, pin_code: string}
     */
    public function createRide(
        User $passenger,
        int $cityId,
        int $vehicleClassId,
        float $pickupLat,
        float $pickupLng,
        string $pickupAddress,
        float $destinationLat,
        float $destinationLng,
        string $destinationAddress,
        PaymentMethod $paymentMethod,
    ): array {
        $pricing = PricingConfig::currentFor($cityId, $vehicleClassId);

        if (! $pricing) {
            throw new \RuntimeException('No active pricing configuration for this city and vehicle class.');
        }

        $estimate = $this->fareEstimationService->estimate(
            $pricing,
            $pickupLat, $pickupLng,
            $destinationLat, $destinationLng,
            $pricing->city->currency_code ?? 'NGN',
        );

        return DB::transaction(function () use (
            $passenger, $cityId, $vehicleClassId,
            $pickupLat, $pickupLng, $pickupAddress,
            $destinationLat, $destinationLng, $destinationAddress,
            $paymentMethod, $estimate,
        ) {
            $ride = Ride::create([
                'city_id' => $cityId,
                'vehicle_class_id' => $vehicleClassId,
                'passenger_id' => $passenger->id,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'pickup_address' => $pickupAddress,
                'destination_lat' => $destinationLat,
                'destination_lng' => $destinationLng,
                'destination_address' => $destinationAddress,
                'status' => RideStatus::Requested,
                'share_token' => Str::random(32),
                'fare_estimate_amount' => $estimate['fare_estimate'],
                'fare_currency' => $estimate['currency'],
                'pricing_snapshot' => $estimate['pricing_snapshot'],
                'payment_method' => $paymentMethod,
                'payment_status' => PaymentStatus::Pending,
            ]);

            $pin = $this->pinService->generatePin($ride);
            $ride->update(['pin_code' => $pin['pin_code']]);

            $this->stateMachine->transitionTo(
                $ride,
                RideStatus::Searching,
                $passenger,
                'passenger',
            );

            AuditLog::record($ride, 'ride_created', $passenger);

            return ['ride' => $ride->fresh(), 'pin_code' => $pin['pin_code']];
        });
    }

    public function cancelRide(
        Ride $ride,
        User $cancelledBy,
        ?string $reason = null,
    ): Ride {
        return DB::transaction(function () use ($ride, $cancelledBy, $reason) {
            $ride->update([
                'cancelled_by' => $cancelledBy->id,
                'cancellation_reason' => $reason,
            ]);

            $triggeredByType = $cancelledBy->isDriver() ? 'driver' : 'passenger';

            $this->stateMachine->transitionTo(
                $ride,
                RideStatus::Cancelled,
                $cancelledBy,
                $triggeredByType,
                ['reason' => $reason],
            );

            AuditLog::record($ride, 'ride_cancelled', $cancelledBy, null, [
                'reason' => $reason,
                'cancelled_by_type' => $triggeredByType,
                'previous_status' => $ride->getOriginal('status'),
            ]);

            return $ride->fresh();
        });
    }

    public function driverArrived(Ride $ride, User $driver): Ride
    {
        return $this->stateMachine->transitionTo(
            $ride,
            RideStatus::DriverArrived,
            $driver,
            'driver',
        );
    }

    /**
     * @return array{success: bool, ride: Ride, error: ?string}
     */
    public function verifyPinAndStart(Ride $ride, User $driver, string $pinCode): array
    {
        $verification = $this->pinService->verifyPin($ride, $pinCode);

        if (! $verification['valid']) {
            return [
                'success' => false,
                'ride' => $ride,
                'error' => $verification['error'],
            ];
        }

        $ride = $this->stateMachine->transitionTo(
            $ride,
            RideStatus::InProgress,
            $driver,
            'driver',
            ['pin_verified' => true],
        );

        return [
            'success' => true,
            'ride' => $ride,
            'error' => null,
        ];
    }

    public function completeRide(Ride $ride, User $driver): Ride
    {
        return DB::transaction(function () use ($ride, $driver) {
            $ride = $this->stateMachine->transitionTo(
                $ride,
                RideStatus::Completed,
                $driver,
                'driver',
            );

            AuditLog::record($ride, 'ride_completed', $driver);

            return $ride;
        });
    }

    /**
     * @return array{final_fare: float, distance_km: float, duration_minutes: float, waiting_charge: float, fare_breakdown: array}
     */
    public function calculateFinalFare(Ride $ride): array
    {
        $route = $this->mapsGateway->getDistanceAndDuration(
            (float) $ride->pickup_lat, (float) $ride->pickup_lng,
            (float) $ride->destination_lat, (float) $ride->destination_lng,
        );

        $snapshot = $ride->pricing_snapshot;
        $pricing = PricingConfig::find($snapshot['pricing_config_id']);

        if (! $pricing) {
            $pricing = PricingConfig::currentFor($ride->city_id, $ride->vehicle_class_id);
        }

        if (! $pricing) {
            throw new \RuntimeException('Unable to determine pricing for fare calculation.');
        }

        $waitingMinutes = 0;
        if ($ride->matched_at && $ride->started_at) {
            $arrivedTransition = $ride->stateTransitions()
                ->where('to_state', RideStatus::DriverArrived->value)
                ->first();

            if ($arrivedTransition) {
                $freeMinutes = $pricing->free_waiting_minutes ?? 5;
                $totalWaiting = $arrivedTransition->created_at->diffInMinutes($ride->started_at);
                $waitingMinutes = max(0, $totalWaiting - $freeMinutes);
            }
        }

        $finalFare = $this->fareEstimationService->calculateFare(
            $pricing,
            $route['distance_km'],
            $route['duration_minutes'],
            $waitingMinutes,
        );

        $ride->update(['final_fare_amount' => $finalFare]);

        return [
            'final_fare' => $finalFare,
            'distance_km' => $route['distance_km'],
            'duration_minutes' => $route['duration_minutes'],
            'waiting_charge' => $this->fareEstimationService->calculateWaitingCharge($pricing, $waitingMinutes),
            'fare_breakdown' => [
                'base_fare' => (float) $pricing->base_fare,
                'distance_charge' => round($route['distance_km'] * (float) $pricing->per_km_rate, 2),
                'time_charge' => round($route['duration_minutes'] * (float) $pricing->per_minute_rate, 2),
                'waiting_charge' => $this->fareEstimationService->calculateWaitingCharge($pricing, $waitingMinutes),
                'minimum_fare' => (float) $pricing->minimum_fare,
            ],
        ];
    }
}
