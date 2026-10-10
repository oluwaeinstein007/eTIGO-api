<?php

namespace App\Services;

use App\Enums\RideStatus;
use App\Events\NearbyMapChanged;
use App\Events\RideNoDriverFound;
use App\Events\RideStatusUpdated;
use App\Models\Ride;
use App\Models\RideStateTransition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RideStateMachine
{
    public function __construct(
        private RideNotificationService $notificationService,
    ) {}

    /**
     * @var array<string, list<RideStatus>>
     */
    private const TRANSITIONS = [
        'requested' => [RideStatus::Searching, RideStatus::Cancelled],
        'searching' => [RideStatus::Searching, RideStatus::Matched, RideStatus::NoDriverFound, RideStatus::Cancelled],
        'matched' => [RideStatus::DriverEnRoute, RideStatus::Cancelled],
        'driver_en_route' => [RideStatus::DriverArrived, RideStatus::Cancelled],
        'driver_arrived' => [RideStatus::InProgress, RideStatus::Cancelled],
        'in_progress' => [RideStatus::Completed],
        'completed' => [],
        'cancelled' => [],
        'no_driver_found' => [RideStatus::Searching],
    ];

    public function canTransitionTo(Ride $ride, RideStatus $newStatus): bool
    {
        $currentStatus = $ride->status instanceof RideStatus
            ? $ride->status->value
            : $ride->status;

        $allowed = self::TRANSITIONS[$currentStatus] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    /**
     * @return list<RideStatus>
     */
    public function allowedTransitions(Ride $ride): array
    {
        $currentStatus = $ride->status instanceof RideStatus
            ? $ride->status->value
            : $ride->status;

        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     *
     * @throws \InvalidArgumentException
     */
    public function transitionTo(
        Ride $ride,
        RideStatus $newStatus,
        ?User $actor = null,
        string $triggeredByType = 'system',
        ?array $metadata = null,
    ): Ride {
        /** @var RideStatus $fromState */
        $fromState = null;
        /** @var Carbon $occurredAt */
        $occurredAt = null;

        $ride = DB::transaction(function () use ($ride, $newStatus, $actor, $triggeredByType, $metadata, &$fromState, &$occurredAt) {
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);

            if (! $this->canTransitionTo($ride, $newStatus)) {
                $current = $ride->status instanceof RideStatus
                    ? $ride->status->value
                    : $ride->status;

                throw new \InvalidArgumentException(
                    "Invalid ride state transition from '{$current}' to '{$newStatus->value}'."
                );
            }

            $fromState = $ride->status;
            $occurredAt = now();

            $ride->status = $newStatus;

            $timestampMap = [
                RideStatus::Matched->value => 'matched_at',
                RideStatus::InProgress->value => 'started_at',
                RideStatus::Completed->value => 'completed_at',
            ];

            if (isset($timestampMap[$newStatus->value])) {
                $ride->{$timestampMap[$newStatus->value]} = now();
            }

            $ride->save();

            RideStateTransition::create([
                'ride_id' => $ride->id,
                'from_state' => $fromState instanceof RideStatus ? $fromState->value : $fromState,
                'to_state' => $newStatus->value,
                'triggered_by_type' => $triggeredByType,
                'triggered_by_id' => $actor?->id,
                'metadata' => $metadata,
                'created_at' => $occurredAt,
            ]);

            return $ride;
        });

        $from = $fromState instanceof RideStatus ? $fromState : RideStatus::from($fromState);

        try {
            $this->notificationService->notifyTransition($ride, $from, $newStatus);
        } catch (\Throwable) {
            // Push notification failures must not break the ride flow
        }

        try {
            event(new RideStatusUpdated(
                rideId: (string) $ride->id,
                passengerId: (string) $ride->passenger_id,
                driverId: $ride->driver_id === null ? null : (string) $ride->driver_id,
                previousStatus: $from->value,
                status: $newStatus->value,
                actorType: $triggeredByType,
                actorId: $actor?->id === null ? null : (string) $actor->id,
                occurredAt: $occurredAt->toISOString(),
            ));
        } catch (\Throwable) {
            // Reverb failures must not roll back a committed ride transition.
        }

        if ($from === RideStatus::Searching || $newStatus === RideStatus::Searching) {
            try {
                if (Cache::add('nearby_map:drivers', true, 5)) {
                    NearbyMapChanged::dispatch('drivers');
                }
            } catch (\Throwable) {
                // Map refresh signaling must not break the ride transition.
            }
        }

        if ($newStatus === RideStatus::NoDriverFound) {
            try {
                event(new RideNoDriverFound(
                    rideId: (string) $ride->id,
                    passengerId: (string) $ride->passenger_id,
                    occurredAt: $occurredAt->toISOString(),
                ));
            } catch (\Throwable) {
                // Reverb failures must not roll back a committed ride transition.
            }
        }

        return $ride;
    }
}
