<?php

namespace App\Services;

use App\Enums\CancellationReason;
use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Rides booked ahead of time (Phase 2, BE2-SCH). Booking goes through
 * RideService::createRide; this service checks the pickup time, edits a
 * booking, and runs the dispatcher and reminder that the scheduler drives
 * every minute (ProcessScheduledRidesJob).
 */
class ScheduledRideService
{
    /**
     * Passenger-facing explanations stored on the ride when the dispatcher
     * has to cancel a booking, keyed by dispatch_failure_reason.
     */
    private const FAILURE_MESSAGES = [
        'insufficient_wallet_balance' => 'Your scheduled ride was cancelled because your wallet balance could not cover the fare. Top up and book again.',
        'wallet_unavailable' => 'Your scheduled ride was cancelled because your wallet is unavailable. Please contact support.',
        'account_suspended' => 'Your scheduled ride was cancelled because your account is suspended.',
        'service_unavailable' => 'Your scheduled ride was cancelled because this ride type is no longer available in your city.',
        'missed_dispatch_window' => 'Sorry, we could not start your scheduled ride in time. Please book a new ride.',
    ];

    public function __construct(
        private RideStateMachine $stateMachine,
        private RidePinService $pinService,
        private RideService $rideService,
        private WalletPaymentService $walletPaymentService,
        private FareEstimationService $fareEstimationService,
        private PromoValidationService $promoValidationService,
        private TierGateService $tierGateService,
        private NotificationService $notificationService,
        private ScheduledRideReservationService $reservationService,
        private ThirdPartyBookingService $thirdPartyBookingService,
        private ThirdPartyRoutingService $thirdPartyRoutingService,
    ) {}

    /**
     * Why a pickup time cannot be booked (an empty list when it can). Pass
     * the booking being edited so it is not compared with itself, and the
     * rider's phone for a booking made for someone else: bookings only clash
     * when they are for the same person.
     *
     * @return list<string>
     */
    public function pickupTimeErrors(User $passenger, CarbonInterface $pickupAt, ?Ride $editing = null, ?string $riderPhone = null): array
    {
        $settings = AppSetting::scheduledRides();

        if ($editing === null && ! $settings['enabled']) {
            return ['Scheduled rides are not available right now.'];
        }

        $errors = [];

        if ($pickupAt->lt(now()->addMinutes($settings['min_lead_minutes']))) {
            $errors[] = "The pickup time must be at least {$settings['min_lead_minutes']} minutes from now.";
        }

        if ($pickupAt->gt(now()->addDays($settings['max_advance_days']))) {
            $errors[] = "The pickup time can be at most {$settings['max_advance_days']} days from now.";
        }

        $upcoming = Ride::query()
            ->where('passenger_id', $passenger->id)
            ->where('status', RideStatus::Scheduled)
            ->when($editing, fn ($query) => $query->whereKeyNot($editing->id))
            ->with('scheduledRide')
            ->get();

        if ($editing === null && $upcoming->count() >= $settings['max_upcoming_per_passenger']) {
            $errors[] = "You can have at most {$settings['max_upcoming_per_passenger']} upcoming scheduled rides.";
        }

        $gap = $settings['min_minutes_between_bookings'];
        $clash = $upcoming->first(fn (Ride $ride) => $ride->scheduledRide !== null
            && $ride->rider_phone === $riderPhone
            && abs($ride->scheduledRide->scheduled_pickup_at->diffInMinutes($pickupAt)) < $gap);

        if ($clash !== null) {
            $errors[] = "You already have a scheduled ride within {$gap} minutes of this time.";
        }

        return $errors;
    }

    /**
     * Whether a booking made now for this pickup time needs no separate
     * reminder because the pickup is already inside the reminder window (the
     * booking itself is the reminder).
     */
    public static function reminderAlreadyCovered(CarbonInterface $pickupAt): bool
    {
        $minutes = AppSetting::scheduledRides()['reminder_minutes'];

        return $minutes <= 0 || $pickupAt->lte(now()->addMinutes($minutes));
    }

    /**
     * Change the pickup time, places or payment method of a booking that has
     * not been dispatched yet. The fare is quoted again and the promo code,
     * if any, is checked again for the new trip.
     *
     * @param  array{scheduled_pickup_at?: string, pickup_lat?: float, pickup_lng?: float, pickup_address?: string, destination_lat?: float, destination_lng?: float, destination_address?: string, payment_method?: string}  $changes
     *
     * @throws ValidationException
     */
    public function update(Ride $ride, User $passenger, array $changes): Ride
    {
        return DB::transaction(function () use ($ride, $passenger, $changes) {
            $ride = Ride::lockForUpdate()->with('scheduledRide')->findOrFail($ride->id);
            $scheduled = $ride->scheduledRide;

            if ($ride->status !== RideStatus::Scheduled || $scheduled === null || ! $scheduled->isUpcoming()) {
                throw ValidationException::withMessages([
                    'ride' => ['Only a scheduled ride that has not started can be changed.'],
                ]);
            }

            $settings = AppSetting::scheduledRides();

            if (now()->gte($scheduled->dispatchAt($settings['dispatch_lead_minutes']))) {
                throw ValidationException::withMessages([
                    'ride' => ['This ride is about to start looking for a driver and can no longer be changed. Cancel it and book again instead.'],
                ]);
            }

            $pickupAt = isset($changes['scheduled_pickup_at'])
                ? Carbon::parse($changes['scheduled_pickup_at'])->utc()
                : $scheduled->scheduled_pickup_at;

            if (isset($changes['scheduled_pickup_at'])) {
                $errors = $this->pickupTimeErrors($passenger, $pickupAt, $ride, $ride->is_third_party ? $ride->rider_phone : null);

                if ($errors !== []) {
                    throw ValidationException::withMessages(['scheduled_pickup_at' => $errors]);
                }
            }

            $before = $this->editableSnapshot($ride, $scheduled);

            $ride->fill(array_intersect_key($changes, array_flip([
                'pickup_lat', 'pickup_lng', 'pickup_address',
                'destination_lat', 'destination_lng', 'destination_address',
            ])));

            if (isset($changes['payment_method'])) {
                $ride->payment_method = PaymentMethod::from($changes['payment_method']);

                if ($ride->is_third_party && ! $this->thirdPartyBookingService->paymentMethodAllowed($ride->payment_method)) {
                    throw ValidationException::withMessages([
                        'payment_method' => ["{$ride->payment_method->label()} can't be used when you book for someone else. Pay by wallet or card."],
                    ]);
                }
            }

            $this->requote($ride, $passenger, $pickupAt);

            if ($ride->payment_method === PaymentMethod::Wallet) {
                try {
                    $this->walletPaymentService->assertCanCover($passenger, (int) round($ride->fare_estimate_amount * 100));
                } catch (\DomainException $e) {
                    throw ValidationException::withMessages(['payment_method' => [$e->getMessage()]]);
                }
            }

            $ride->save();

            $timeChanged = ! $pickupAt->equalTo($scheduled->scheduled_pickup_at);
            $pickupMoved = $ride->wasChanged(['pickup_lat', 'pickup_lng', 'pickup_address']);

            // A reserved driver committed to a time and place; a new one
            // returns the booking to the pool.
            if ($scheduled->isReserved() && ($timeChanged || $pickupMoved)) {
                $this->reservationService->clearReservation($ride, $scheduled, 'pickup_changed', $passenger);
            }

            $scheduled->scheduled_pickup_at = $pickupAt;

            if ($timeChanged) {
                $scheduled->reminder_sent_at = self::reminderAlreadyCovered($pickupAt) ? now() : null;
            }

            $scheduled->save();

            AuditLog::record($ride, 'scheduled_ride_updated', $passenger, $before, $this->editableSnapshot($ride, $scheduled));

            return $ride->fresh('scheduledRide');
        });
    }

    /**
     * Quote the ride again for its (possibly new) route and pickup time.
     *
     * @throws ValidationException when there is no pricing or the promo code no longer applies
     */
    private function requote(Ride $ride, User $passenger, CarbonInterface $pickupAt): void
    {
        $pricing = PricingConfig::currentFor($ride->city_id, $ride->vehicle_class_id);

        if (! $pricing) {
            throw ValidationException::withMessages([
                'vehicle_class_id' => ['Pricing is not configured for this vehicle class in your city.'],
            ]);
        }

        $tierBenefits = $this->tierGateService->getUserBenefits($passenger->id);
        $bookingFeeDiscountPct = (float) $tierBenefits['booking_fee_discount_pct'];

        $estimate = $this->fareEstimationService->estimate(
            $pricing,
            (float) $ride->pickup_lat, (float) $ride->pickup_lng,
            (float) $ride->destination_lat, (float) $ride->destination_lng,
            $pricing->city->currency_code ?? 'NGN',
            $bookingFeeDiscountPct,
            $pickupAt,
        );

        if ($ride->promo_code_id) {
            $ride->loadMissing('promoCode');
            $validation = $ride->promoCode
                ? $this->promoValidationService->validate(
                    code: $ride->promoCode->code,
                    userId: $passenger->id,
                    cityId: $ride->city_id,
                    vehicleClassId: $ride->vehicle_class_id,
                    pickupLat: (float) $ride->pickup_lat,
                    pickupLng: (float) $ride->pickup_lng,
                    fareAmount: (float) $estimate['fare_estimate'],
                    evaluatedAt: $pickupAt,
                )
                : ['valid' => false, 'reason' => 'The promo code on this booking no longer exists.'];

            if (! $validation['valid']) {
                throw ValidationException::withMessages([
                    'promo_code' => [$validation['reason'].' Cancel this booking and book again without it to make this change.'],
                ]);
            }
        }

        $ride->fare_estimate_amount = $estimate['fare_estimate'];
        $ride->fare_currency = $estimate['currency'];
        $ride->pricing_snapshot = [
            ...$estimate['pricing_snapshot'],
            'distance_km' => $estimate['distance_km'],
            'duration_minutes' => $estimate['duration_minutes'],
            'surge_multiplier' => (float) $estimate['surge']['multiplier'],
            'surge_rule_name' => $estimate['surge']['rule_name'],
            'booking_fee_discount_pct' => $bookingFeeDiscountPct,
            'tier_name' => $tierBenefits['tier_name'],
            'priority_matching' => (bool) $tierBenefits['priority_matching'],
            'reservation_fee' => $estimate['reservation_fee'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function editableSnapshot(Ride $ride, ScheduledRide $scheduled): array
    {
        return [
            'scheduled_pickup_at' => $scheduled->scheduled_pickup_at->toIso8601String(),
            'pickup_address' => $ride->pickup_address,
            'destination_address' => $ride->destination_address,
            'payment_method' => $ride->payment_method?->value,
            'fare_estimate_amount' => (string) $ride->fare_estimate_amount,
        ];
    }

    /**
     * Start matching for a booking whose dispatch time has come: place the
     * wallet hold, issue the PIN and move the ride to `searching`. Bookings
     * that cannot start are cancelled with a reason the passenger is told.
     * Safe to run more than once for the same booking.
     *
     * @return 'dispatched'|'assigned'|'waiting'|'failed'|'skipped' assigned = given straight
     *                                                              to the reserved driver; waiting = the passenger is still on another ride
     *                                                              and the next sweep tries again
     */
    public function dispatch(string $scheduledRideId): string
    {
        $failure = null;
        $rideId = null;

        $outcome = DB::transaction(function () use ($scheduledRideId, &$failure, &$rideId) {
            // Lock the ride before its booking row, the same order as
            // cancelling and editing, so a cancel during dispatch cannot deadlock.
            $bookedRideId = ScheduledRide::whereKey($scheduledRideId)->value('ride_id');
            $ride = $bookedRideId ? Ride::lockForUpdate()->with(['passenger', 'city'])->find($bookedRideId) : null;
            $scheduled = ScheduledRide::lockForUpdate()->find($scheduledRideId);

            if ($ride === null || $scheduled === null || ! $scheduled->isUpcoming() || $ride->status !== RideStatus::Scheduled) {
                return 'skipped';
            }

            $rideId = $ride->id;
            $settings = AppSetting::scheduledRides();

            // The booking was moved later after the sweep picked it up.
            if (now()->lt($scheduled->dispatchAt($settings['dispatch_lead_minutes']))) {
                return 'skipped';
            }

            $graceEnd = $scheduled->scheduled_pickup_at->copy()->addMinutes($settings['matching_grace_minutes']);

            $failure = match (true) {
                now()->gte($graceEnd) => 'missed_dispatch_window',
                ! $ride->passenger?->is_active => 'account_suspended',
                ! $this->isServiceAvailable($ride) => 'service_unavailable',
                default => null,
            };

            if ($failure !== null) {
                $this->failDispatch($ride, $scheduled, $failure);

                return 'failed';
            }

            // The booker's own ride now does not hold up one booked for
            // someone else, nor the other way round.
            $passengerBusy = ! $ride->is_third_party && Ride::query()
                ->where('passenger_id', $ride->passenger_id)
                ->ownTrips()
                ->whereKeyNot($ride->id)
                ->whereIn('status', RideStatus::activeStatuses())
                ->exists();

            if ($passengerBusy) {
                return 'waiting';
            }

            if ($ride->payment_method === PaymentMethod::Wallet) {
                try {
                    $this->walletPaymentService->placeHold($ride->passenger, $ride, (int) round($ride->fare_estimate_amount * 100));
                } catch (\DomainException $e) {
                    $failure = $e->getMessage() === 'Insufficient wallet balance.'
                        ? 'insufficient_wallet_balance'
                        : 'wallet_unavailable';
                    $this->failDispatch($ride, $scheduled, $failure);

                    return 'failed';
                }
            }

            $this->pinService->generatePin($ride);

            $scheduled->update(['dispatched_at' => now()]);

            $ride = $this->stateMachine->transitionTo(
                $ride,
                RideStatus::Searching,
                null,
                'system',
                [
                    'reason' => 'scheduled_dispatch',
                    'scheduled_pickup_at' => $scheduled->scheduled_pickup_at->toIso8601String(),
                ],
            );

            AuditLog::record($ride, 'scheduled_ride_dispatched');

            // A driver who reserved the booking gets it directly when they are
            // online and free; otherwise it goes to normal matching.
            $reservedDriver = $scheduled->reserved_driver_id ? User::find($scheduled->reserved_driver_id) : null;

            if ($reservedDriver !== null) {
                if ($this->reservationService->canTakeNow($reservedDriver, $ride)
                    && $this->rideService->assignReservedDriver($ride, $reservedDriver, ['reason' => 'scheduled_dispatch'])) {
                    return 'assigned';
                }

                $this->reservationService->clearReservation($ride, $scheduled, 'driver_unavailable');
            }

            return 'dispatched';
        });

        if ($outcome === 'dispatched') {
            $this->rideService->startMatching(Ride::with('scheduledRide')->findOrFail($rideId));
        }

        if ($outcome === 'failed') {
            Log::warning('Scheduled ride could not be dispatched', [
                'scheduled_ride_id' => $scheduledRideId,
                'reason' => $failure,
            ]);
        }

        return $outcome;
    }

    private function isServiceAvailable(Ride $ride): bool
    {
        if (! $ride->city?->is_active) {
            return false;
        }

        return DB::table('city_vehicle_classes')
            ->join('vehicle_classes', 'vehicle_classes.id', '=', 'city_vehicle_classes.vehicle_class_id')
            ->where('city_vehicle_classes.city_id', $ride->city_id)
            ->where('city_vehicle_classes.vehicle_class_id', $ride->vehicle_class_id)
            ->where('city_vehicle_classes.is_active', true)
            ->where('vehicle_classes.is_active', true)
            ->exists();
    }

    private function failDispatch(Ride $ride, ScheduledRide $scheduled, string $reason): void
    {
        $ride->update([
            'cancelled_by' => null,
            'cancellation_reason' => CancellationReason::ScheduledDispatchFailed->value,
            'cancellation_details' => self::FAILURE_MESSAGES[$reason],
        ]);

        $scheduled->update([
            'cancelled_at' => now(),
            'dispatch_failure_reason' => $reason,
        ]);

        $this->stateMachine->transitionTo(
            $ride,
            RideStatus::Cancelled,
            null,
            'system',
            ['reason' => 'scheduled_dispatch_failed', 'failure' => $reason],
        );

        AuditLog::record($ride, 'scheduled_ride_dispatch_failed', null, null, ['reason' => $reason]);
    }

    /**
     * Remind the passenger of an upcoming booking, warning a wallet passenger
     * whose balance would not cover the fare while there is still time to
     * top up. Sent once per pickup time.
     */
    public function sendReminder(string $scheduledRideId): bool
    {
        $scheduled = DB::transaction(function () use ($scheduledRideId) {
            $scheduled = ScheduledRide::lockForUpdate()->find($scheduledRideId);

            if ($scheduled === null || ! $scheduled->isUpcoming() || $scheduled->reminder_sent_at !== null) {
                return null;
            }

            $scheduled->update(['reminder_sent_at' => now()]);

            return $scheduled;
        });

        if ($scheduled === null) {
            return false;
        }

        $ride = Ride::with(['city', 'passenger'])->find($scheduled->ride_id);

        if ($ride === null || $ride->status !== RideStatus::Scheduled) {
            return false;
        }

        $time = $scheduled->scheduled_pickup_at->copy()
            ->setTimezone($ride->city?->timezone ?? config('app.timezone'))
            ->format('g:i A');

        $body = $ride->is_third_party
            ? "The ride you booked for {$ride->riderFirstName()} is at {$time} from {$ride->pickup_address}."
            : "Your ride is booked for {$time} from {$ride->pickup_address}.";

        if ($ride->payment_method === PaymentMethod::Wallet
            && $this->walletPaymentService->availableBalance($ride->passenger) < (int) round($ride->fare_estimate_amount * 100)) {
            $body .= ' Your wallet balance is too low for this trip. Top up before then or the booking will be cancelled.';
        }

        try {
            $this->notificationService->send(
                userId: $ride->passenger_id,
                type: NotificationType::ScheduledRideReminder,
                title: 'Upcoming Scheduled Ride',
                body: $body,
                data: [
                    'ride_id' => (string) $ride->id,
                    'scheduled_pickup_at' => $scheduled->scheduled_pickup_at->toIso8601String(),
                ],
            );
        } catch (\Throwable $e) {
            // Give the claim back so the next sweep tries again, unless the
            // booking moved on meanwhile.
            ScheduledRide::query()
                ->whereKey($scheduled->id)
                ->whereNull('dispatched_at')
                ->whereNull('cancelled_at')
                ->where('reminder_sent_at', $scheduled->reminder_sent_at)
                ->update(['reminder_sent_at' => null]);

            Log::warning('Scheduled ride reminder could not be sent', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // The rider of a booking made for them hears about it too (BE2-3P-08);
        // the PIN follows at dispatch with the driver's details.
        try {
            $this->thirdPartyRoutingService->queue($ride->loadMissing('scheduledRide'), ThirdPartyRoutingService::REMINDER);
        } catch (\Throwable $e) {
            Log::warning('Third-party rider reminder could not be queued', ['ride_id' => $ride->id, 'error' => $e->getMessage()]);
        }

        return true;
    }

    /**
     * Remind the reserved driver of their pickup, once per reservation, so
     * they are online when matching starts.
     */
    public function sendDriverReminder(string $scheduledRideId): bool
    {
        $scheduled = DB::transaction(function () use ($scheduledRideId) {
            $scheduled = ScheduledRide::lockForUpdate()->find($scheduledRideId);

            if ($scheduled === null || ! $scheduled->isUpcoming() || ! $scheduled->isReserved() || $scheduled->driver_reminder_sent_at !== null) {
                return null;
            }

            $scheduled->update(['driver_reminder_sent_at' => now()]);

            return $scheduled;
        });

        if ($scheduled === null) {
            return false;
        }

        $ride = Ride::with('city')->find($scheduled->ride_id);
        $timezone = $ride?->city?->timezone ?? config('app.timezone');
        $pickup = $scheduled->scheduled_pickup_at->copy()->setTimezone($timezone)->format('g:i A');
        $onlineBy = $scheduled->dispatchAt(AppSetting::scheduledRides()['dispatch_lead_minutes'])->setTimezone($timezone)->format('g:i A');

        try {
            $this->notificationService->send(
                userId: $scheduled->reserved_driver_id,
                type: NotificationType::ScheduledRideReminder,
                title: 'Upcoming Reserved Pickup',
                body: "Reserved pickup at {$pickup} from {$ride?->pickup_address}. Be online by {$onlineBy} to keep it.",
                data: [
                    'ride_id' => (string) $scheduled->ride_id,
                    'scheduled_pickup_at' => $scheduled->scheduled_pickup_at->toIso8601String(),
                ],
            );
        } catch (\Throwable $e) {
            ScheduledRide::query()
                ->whereKey($scheduled->id)
                ->where('driver_reminder_sent_at', $scheduled->driver_reminder_sent_at)
                ->update(['driver_reminder_sent_at' => null]);

            Log::warning('Reserved driver reminder could not be sent', ['scheduled_ride_id' => $scheduled->id, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }
}
