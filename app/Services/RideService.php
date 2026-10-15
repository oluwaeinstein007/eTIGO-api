<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Jobs\CalculateCarbonScoreJob;
use App\Jobs\DispatchRideRequestJob;
use App\Jobs\MatchingTimeoutJob;
use App\Jobs\MonitorCancellationJob;
use App\Jobs\ProcessPaymentJob;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\RideStateTransition;
use App\Models\User;
use App\Notifications\RideCompletedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
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
        private OfflineTripDetectionService $offlineTripDetectionService,
        private PromoValidationService $promoValidationService,
        private PromoApplicationService $promoApplicationService,
        private TierGateService $tierGateService,
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
        ?string $promoCode = null,
    ): array {
        $pricing = PricingConfig::currentFor($cityId, $vehicleClassId);

        if (! $pricing) {
            throw new \RuntimeException('No active pricing configuration for this city and vehicle class.');
        }

        $tierBenefits = $this->tierGateService->getUserBenefits($passenger->id);
        $bookingFeeDiscountPct = (float) $tierBenefits['booking_fee_discount_pct'];

        $estimate = $this->fareEstimationService->estimate(
            $pricing,
            $pickupLat, $pickupLng,
            $destinationLat, $destinationLng,
            $pricing->city->currency_code ?? 'NGN',
            $bookingFeeDiscountPct,
        );

        $validatedPromoId = null;
        if ($promoCode) {
            // The ride being booked supplies trusted context, so every promo
            // rule is checked now rather than silently dropping the discount
            // at completion.
            $validation = $this->promoValidationService->validate(
                code: $promoCode,
                userId: $passenger->id,
                cityId: $cityId,
                vehicleClassId: $vehicleClassId,
                pickupLat: $pickupLat,
                pickupLng: $pickupLng,
                fareAmount: (float) $estimate['fare_estimate'],
            );
            if (! $validation['valid']) {
                throw ValidationException::withMessages([
                    'promo_code' => [$validation['reason']],
                ]);
            }
            $validatedPromoId = $validation['promo']->id;
        }

        $result = DB::transaction(function () use (
            $passenger, $cityId, $vehicleClassId,
            $pickupLat, $pickupLng, $pickupAddress,
            $destinationLat, $destinationLng, $destinationAddress,
            $paymentMethod, $estimate, $validatedPromoId,
            $bookingFeeDiscountPct, $tierBenefits,
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
                    'surge_multiplier' => (float) $estimate['surge']['multiplier'],
                    'surge_rule_name' => $estimate['surge']['rule_name'],
                    'booking_fee_discount_pct' => $bookingFeeDiscountPct,
                    'tier_name' => $tierBenefits['tier_name'],
                    'priority_matching' => (bool) $tierBenefits['priority_matching'],
                ],
                'promo_code_id' => $validatedPromoId,
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

        $timeout = config('matching.matching_timeout', 300);
        MatchingTimeoutJob::dispatch($result['ride']->id, $result['ride']->searchAttemptCount())
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

        $driverUserId = $ride->driver_id;

        $result = DB::transaction(function () use ($ride, $cancelledBy, $reason, $reasonDetails, $previousStatus) {
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
            Cache::forget("ride:{$ride->id}:pin_code");

            return $ride->fresh();
        });

        // The cancellation has committed; anti-offline monitoring is best-effort
        // and must not turn a successful cancel into an error if Redis is down.
        try {
            if ($driverUserId && $this->offlineTripDetectionService->shouldMonitor($ride)) {
                MonitorCancellationJob::dispatch($ride->id, $driverUserId);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not start offline-trip monitoring after cancellation', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    public function expireStaleSearch(Ride $ride): Ride
    {
        return DB::transaction(function () use ($ride) {
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);

            if (! in_array($ride->status, [RideStatus::Requested, RideStatus::Searching], true)) {
                return $ride;
            }

            if ($ride->status === RideStatus::Requested) {
                $ride = $this->stateMachine->transitionTo(
                    $ride,
                    RideStatus::Searching,
                    null,
                    'system',
                    ['reason' => 'auto_expired_stale_search'],
                );
            }

            $ride = $this->stateMachine->transitionTo(
                $ride,
                RideStatus::NoDriverFound,
                null,
                'system',
                ['reason' => 'stale_search_timeout'],
            );

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
        MatchingTimeoutJob::dispatch($ride->id, $ride->searchAttemptCount())
            ->delay(now()->addSeconds(config('matching.matching_timeout', 300)))
            ->afterCommit();

        return $ride;
    }

    /**
     * @return array{success: bool, ride: Ride, error: ?string}
     */
    public function acceptRide(Ride $ride, User $driver): array
    {
        $result = DB::transaction(function () use ($ride, $driver) {
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);

            if ($ride->status !== RideStatus::Searching) {
                return 'Ride is no longer available.';
            }

            if ($this->driverHasOtherActiveRide($driver, $ride)) {
                return 'You already have an active ride. Complete it before accepting another.';
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

        if (! $result instanceof Ride) {
            return ['success' => false, 'ride' => new Ride, 'error' => $result];
        }

        $this->matchingService->cleanupRideCache($result);

        return ['success' => true, 'ride' => $result, 'error' => null];
    }

    /**
     * Locks the driver's profile row so concurrent accepts/assignments for the
     * same driver are serialised, then checks for another in-flight ride.
     */
    private function driverHasOtherActiveRide(User $driver, Ride $ride): bool
    {
        Driver::where('user_id', $driver->id)->lockForUpdate()->first();

        return Ride::where('driver_id', $driver->id)
            ->where('id', '!=', $ride->id)
            ->whereIn('status', [
                RideStatus::Matched,
                RideStatus::DriverEnRoute,
                RideStatus::DriverArrived,
                RideStatus::InProgress,
            ])
            ->exists();
    }

    public function rejectRide(Ride $ride, User $driver): void
    {
        $this->matchingService->markDriverRejected($ride, $driver->id);
        $this->matchingService->clearDispatchedDriver($ride);

        AuditLog::record($ride, 'ride_rejected', $driver);

        DispatchRideRequestJob::dispatch($ride->id);
    }

    /**
     * Manually assign a driver. Supports rides still searching and rides that
     * already ended in no_driver_found (the main reason to assign manually).
     *
     * @throws ValidationException when the driver is busy, the passenger has
     *                             moved on to another ride, or a wallet hold cannot be re-placed
     */
    public function adminAssignDriver(Ride $ride, User $driver, User $admin): Ride
    {
        $ride = DB::transaction(function () use ($ride, $driver, $admin) {
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);

            if (! in_array($ride->status, [RideStatus::Requested, RideStatus::Searching, RideStatus::NoDriverFound], true)) {
                throw new InvalidStateTransitionException(
                    'This ride cannot be assigned in its current state.',
                    $ride->status->value,
                    RideStatus::Matched->value,
                );
            }

            if ($this->driverHasOtherActiveRide($driver, $ride)) {
                throw ValidationException::withMessages([
                    'driver_id' => ['Driver already has an active ride.'],
                ]);
            }

            $meta = ['assigned_driver_id' => $driver->id, 'manual_assignment' => true];

            if ($ride->status === RideStatus::NoDriverFound) {
                $passengerHasNewerRide = Ride::where('passenger_id', $ride->passenger_id)
                    ->where('id', '!=', $ride->id)
                    ->whereIn('status', RideStatus::activeStatuses())
                    ->exists();

                if ($passengerHasNewerRide) {
                    throw ValidationException::withMessages([
                        'ride' => ['The passenger already has another active ride.'],
                    ]);
                }

                if ($ride->payment_method === PaymentMethod::Wallet) {
                    try {
                        $this->walletPaymentService->placeHold(
                            $ride->passenger,
                            $ride,
                            (int) round($ride->fare_estimate_amount * 100),
                        );
                    } catch (\DomainException $e) {
                        throw ValidationException::withMessages(['ride' => [$e->getMessage()]]);
                    }
                }

                $ride = $this->stateMachine->transitionTo($ride, RideStatus::Searching, $admin, 'admin', $meta);
            }

            $ride->driver_id = $driver->id;
            $ride->save();

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

        return $ride;
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

        $meteredFare = $this->fareEstimationService->calculateFare(
            $pricing,
            $route['distance_km'],
            $route['duration_minutes'],
            $waitingMinutes,
        );

        // The surge multiplier and tier discount are locked in at booking time
        // (the passenger agreed to that quote); rides booked before these were
        // recorded default to no surge and no discount.
        $surgeMultiplier = (float) ($snapshot['surge_multiplier'] ?? 1.0);
        $adjusted = $this->fareEstimationService->applyAdjustments(
            $pricing,
            $meteredFare,
            $surgeMultiplier,
            (float) ($snapshot['booking_fee_discount_pct'] ?? 0),
        );

        $rawFare = $adjusted['fare'];
        $finalFare = $rawFare;
        $discountAmount = null;
        $promoSnapshot = null;

        if ($ride->promo_code_id) {
            $promo = $ride->promoCode;

            if (! $promo) {
                Log::warning('Promo code attached to ride no longer exists', [
                    'ride_id' => $ride->id,
                    'promo_code_id' => $ride->promo_code_id,
                ]);
            }

            $promoResult = $promo
                ? $this->promoApplicationService->applyToRide($promo, $ride, $rawFare)
                : ['success' => false];

            if ($promoResult['success']) {
                $discountAmount = $promoResult['discount_amount'];
                $finalFare = round(max(0, $rawFare - $discountAmount), 2);
                $promoSnapshot = [
                    'promo_code_id' => $promoResult['promo_code_id'],
                    'promo_code' => $promoResult['code'],
                    'discount_amount' => $discountAmount,
                    'raw_fare' => $rawFare,
                ];
            }
        }

        $waitingCharge = $this->fareEstimationService->calculateWaitingCharge($pricing, $waitingMinutes);
        $tierDiscountSnapshot = $adjusted['booking_fee_discount'] > 0
            ? [
                'tier' => $snapshot['tier_name'] ?? null,
                'percentage' => (float) ($snapshot['booking_fee_discount_pct'] ?? 0),
                'amount' => $adjusted['booking_fee_discount'],
            ]
            : null;

        $ride->update([
            'final_fare_amount' => $finalFare,
            'discount_amount' => $discountAmount,
            'pricing_snapshot' => [
                ...(is_array($snapshot) ? $snapshot : []),
                'distance_km' => $route['distance_km'],
                'duration_minutes' => $route['duration_minutes'],
                'waiting_minutes' => round((float) $waitingMinutes, 2),
                'waiting_charge' => $waitingCharge,
                'surge_multiplier' => $surgeMultiplier,
                ...($tierDiscountSnapshot ? ['tier_discount' => $tierDiscountSnapshot] : []),
                ...($promoSnapshot ? ['promo' => $promoSnapshot] : []),
            ],
        ]);

        return [
            'final_fare' => $finalFare,
            'raw_fare' => $rawFare,
            'discount_amount' => $discountAmount,
            'distance_km' => $route['distance_km'],
            'duration_minutes' => $route['duration_minutes'],
            'waiting_charge' => $waitingCharge,
            'fare_breakdown' => [
                'base_fare' => (float) $pricing->base_fare,
                'distance_charge' => round($route['distance_km'] * (float) $pricing->per_km_rate, 2),
                'time_charge' => round($route['duration_minutes'] * (float) $pricing->per_minute_rate, 2),
                'waiting_charge' => $waitingCharge,
                'minimum_fare' => (float) $pricing->minimum_fare,
                'surge_multiplier' => $surgeMultiplier,
                'surge_amount' => round($adjusted['surged_fare'] - $meteredFare, 2),
                'booking_fee_discount' => $adjusted['booking_fee_discount'],
                'promo_discount' => $discountAmount,
            ],
        ];
    }
}
