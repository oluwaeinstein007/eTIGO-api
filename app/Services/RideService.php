<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Jobs\CalculateCarbonScoreJob;
use App\Jobs\DispatchRideRequestJob;
use App\Jobs\MatchingTimeoutJob;
use App\Jobs\ProcessPaymentJob;
use App\Models\AuditLog;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\RideStateTransition;
use App\Models\User;
use App\Notifications\RideCompletedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RideService
{
    public function __construct(
        private RideStateMachine $stateMachine,
        private RidePinService $pinService,
        private FareEstimationService $fareEstimationService,
        private MapsGateway $mapsGateway,
        private DriverMatchingService $matchingService,
        private WalletPaymentService $walletPaymentService,
    ) {}

    /**
     * @return array{ride: Ride, pin_code: string}
     */
    public function createRide(
        User $passenger,
        string $cityId,
        string $vehicleClassId,
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

        $result = DB::transaction(function () use (
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
                'pricing_snapshot' => [
                    ...$estimate['pricing_snapshot'],
                    'distance_km' => $estimate['distance_km'],
                    'duration_minutes' => $estimate['duration_minutes'],
                ],
                'payment_method' => $paymentMethod,
                'payment_status' => PaymentStatus::Pending,
            ]);

            $pin = $this->pinService->generatePin($ride);

            if ($paymentMethod === PaymentMethod::Wallet) {
                $fareEstimateKobo = (int) round($ride->fare_estimate_amount * 100);
                $this->walletPaymentService->placeHold($passenger, $ride, $fareEstimateKobo);
            }

            $this->stateMachine->transitionTo(
                $ride,
                RideStatus::Searching,
                $passenger,
                'passenger',
            );

            AuditLog::record($ride, 'ride_created', $passenger);

            return ['ride' => $ride->fresh(), 'pin_code' => $pin['pin_code']];
        });

        DispatchRideRequestJob::dispatch($result['ride']->id)->afterCommit();

        $timeout = config('matching.matching_timeout', 180);
        MatchingTimeoutJob::dispatch($result['ride']->id)
            ->delay(now()->addSeconds($timeout))
            ->afterCommit();

        return $result;
    }

    public function cancelRide(
        Ride $ride,
        User $cancelledBy,
        ?string $reason = null,
        ?string $reasonDetails = null,
    ): Ride {
        $previousStatus = $ride->status instanceof RideStatus
            ? $ride->status->value
            : $ride->status;

        return DB::transaction(function () use ($ride, $cancelledBy, $reason, $reasonDetails, $previousStatus) {
            $ride->update([
                'cancelled_by' => $cancelledBy->id,
                'cancellation_reason' => $reason,
                'cancellation_details' => $reasonDetails,
            ]);

            $triggeredByType = match (true) {
                $cancelledBy->isAdmin() => 'admin',
                $cancelledBy->isDriver() => 'driver',
                default => 'passenger',
            };

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
                'previous_status' => $previousStatus,
            ]);

            if ($ride->payment_method === PaymentMethod::Wallet) {
                $this->walletPaymentService->releaseHold($ride);
            }

            $this->matchingService->cleanupRideCache($ride);

            return $ride->fresh();
        });
    }

    public function rebroadcastRide(Ride $ride, User $passenger): Ride
    {
        $ride = DB::transaction(function () use ($ride, $passenger) {
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);

            if (! $ride->belongsToPassenger($passenger)) {
                throw new AuthorizationException;
            }

            if ($ride->status !== RideStatus::NoDriverFound) {
                throw ValidationException::withMessages([
                    'ride' => 'This ride can only be retried after no driver was found.',
                ]);
            }

            $retryCount = RideStateTransition::query()
                ->where('ride_id', $ride->id)
                ->where('to_state', RideStatus::Searching->value)
                ->count();

            if ($ride->payment_method === PaymentMethod::Wallet) {
                $fareEstimateKobo = (int) round($ride->fare_estimate_amount * 100);
                $this->walletPaymentService->placeHold($passenger, $ride, $fareEstimateKobo);
            }

            $ride = $this->stateMachine->transitionTo(
                $ride,
                RideStatus::Searching,
                $passenger,
                'passenger',
                ['reason' => 'passenger_rebroadcast', 'retry_number' => $retryCount],
            );

            $this->matchingService->cleanupRideCache($ride);

            return $ride->fresh();
        });

        DispatchRideRequestJob::dispatch($ride->id)->afterCommit();
        MatchingTimeoutJob::dispatch($ride->id)
            ->delay(now()->addSeconds(config('matching.matching_timeout', 180)))
            ->afterCommit();

        return $ride;
    }

    /**
     * @return array{success: bool, ride: Ride, error: ?string}
     */
    public function acceptRide(Ride $ride, User $driver): array
    {
        $ride = DB::transaction(function () use ($ride, $driver) {
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);

            if ($ride->status !== RideStatus::Searching) {
                return null;
            }

            $ride->driver_id = $driver->id;
            $ride->save();

            $this->stateMachine->transitionTo(
                $ride,
                RideStatus::Matched,
                $driver,
                'driver',
            );

            $this->stateMachine->transitionTo(
                $ride,
                RideStatus::DriverEnRoute,
                $driver,
                'driver',
            );

            AuditLog::record($ride, 'ride_accepted', $driver);

            return $ride->fresh();
        });

        if (! $ride) {
            return ['success' => false, 'ride' => new Ride, 'error' => 'Ride is no longer available.'];
        }

        $this->matchingService->cleanupRideCache($ride);

        return ['success' => true, 'ride' => $ride, 'error' => null];
    }

    public function rejectRide(Ride $ride, User $driver): void
    {
        $this->matchingService->markDriverRejected($ride, $driver->id);
        $this->matchingService->clearDispatchedDriver($ride);

        AuditLog::record($ride, 'ride_rejected', $driver);

        DispatchRideRequestJob::dispatch($ride->id);
    }

    public function adminAssignDriver(Ride $ride, User $driver, User $admin): Ride
    {
        return DB::transaction(function () use ($ride, $driver, $admin) {
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);

            $ride->driver_id = $driver->id;
            $ride->save();

            $meta = ['assigned_driver_id' => $driver->id, 'manual_assignment' => true];

            if ($ride->status === RideStatus::Requested) {
                $this->stateMachine->transitionTo($ride, RideStatus::Searching, $admin, 'admin', $meta);
            }

            $this->stateMachine->transitionTo($ride, RideStatus::Matched, $admin, 'admin', $meta);

            $this->stateMachine->transitionTo(
                $ride,
                RideStatus::DriverEnRoute,
                $admin,
                'admin',
                $meta,
            );

            AuditLog::record($ride, 'ride_manually_assigned', $admin, null, [
                'driver_user_id' => $driver->id,
            ]);

            $this->matchingService->cleanupRideCache($ride);

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
        $ride = DB::transaction(function () use ($ride, $driver) {
            $ride = $this->stateMachine->transitionTo(
                $ride,
                RideStatus::Completed,
                $driver,
                'driver',
            );

            AuditLog::record($ride, 'ride_completed', $driver);

            $fareDetails = null;
            try {
                $fareDetails = $this->calculateFinalFare($ride);
            } catch (\Throwable $e) {
                Log::error('Final fare calculation failed during ride completion', [
                    'ride_id' => $ride->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $passenger = $ride->passenger;
                if ($passenger?->email && $fareDetails) {
                    $passenger->notify(new RideCompletedNotification($ride, $fareDetails));
                }
            } catch (\Throwable) {
                // Notification failures must not break ride completion
            }

            return $ride;
        });

        ProcessPaymentJob::dispatch($ride->id)->afterCommit();
        CalculateCarbonScoreJob::dispatch($ride->id)->afterCommit();

        return $ride;
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
                $waitingMinutes = max(0, $arrivedTransition->created_at->diffInMinutes($ride->started_at));
            }
        }

        $finalFare = $this->fareEstimationService->calculateFare(
            $pricing,
            $route['distance_km'],
            $route['duration_minutes'],
            $waitingMinutes,
        );

        $ride->update([
            'final_fare_amount' => $finalFare,
            'pricing_snapshot' => [
                ...(is_array($snapshot) ? $snapshot : []),
                'distance_km' => $route['distance_km'],
                'duration_minutes' => $route['duration_minutes'],
            ],
        ]);

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
