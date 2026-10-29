<?php

namespace App\Services;

use App\Enums\SosIncidentStatus;
use App\Enums\SosTriggerType;
use App\Events\SosIncidentEscalated;
use App\Events\SosIncidentTriggered;
use App\Events\SosIncidentUpdated;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\SosIncidentTakenException;
use App\Jobs\SendSosCheckInJob;
use App\Jobs\SendSosWhatsAppAlertJob;
use App\Models\Ride;
use App\Models\SosEventLog;
use App\Models\SosIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SosService
{
    public function __construct(
        private DriverLocationService $locationService,
    ) {}

    /**
     * A safety operator may take over or close an incident at any open stage —
     * they must never wait for the automatic escalation to act.
     */
    private const VALID_TRANSITIONS = [
        'triggered' => [
            SosIncidentStatus::CheckInSent,
            SosIncidentStatus::Acknowledged,
            SosIncidentStatus::OperatorAssigned,
            SosIncidentStatus::Resolved,
            SosIncidentStatus::Cancelled,
        ],
        'check_in_sent' => [
            SosIncidentStatus::Acknowledged,
            SosIncidentStatus::Escalated,
            SosIncidentStatus::OperatorAssigned,
            SosIncidentStatus::Resolved,
            SosIncidentStatus::Cancelled,
        ],
        'acknowledged' => [
            SosIncidentStatus::OperatorAssigned,
            SosIncidentStatus::Resolved,
            SosIncidentStatus::Cancelled,
        ],
        'escalated' => [
            SosIncidentStatus::OperatorAssigned,
            SosIncidentStatus::Resolved,
            SosIncidentStatus::Cancelled,
        ],
        'operator_assigned' => [SosIncidentStatus::Dispatched, SosIncidentStatus::Resolved],
        'dispatched' => [SosIncidentStatus::Resolved],
        'resolved' => [],
        'cancelled' => [],
    ];

    /**
     * The driver's last position if it is under two minutes old.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function freshDriverLocation(string $driverUserId): ?array
    {
        try {
            $location = $this->locationService->getDriverLocation($driverUserId);
        } catch (\Throwable) {
            return null;
        }

        if ($location === null || $location['timestamp'] < now()->subMinutes(2)->timestamp) {
            return null;
        }

        return ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
    }

    public function canTransitionTo(SosIncident $incident, SosIncidentStatus $newStatus): bool
    {
        $allowed = self::VALID_TRANSITIONS[$incident->status->value] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    public function trigger(Ride $ride, User $user, array $data): SosIncident
    {
        $triggerType = $user->isDriver()
            ? SosTriggerType::Driver
            : SosTriggerType::Passenger;

        $vehicle = null;
        if ($ride->driver_id) {
            $ride->loadMissing('driver.driver.vehicle');
            $driverRecord = $ride->driver?->driver;
            $vehicle = $driverRecord?->vehicle;
        }

        // On a ride booked for someone else the booker is usually not in the
        // car, so the car's live position is what the Safety team needs. The
        // booker's own position stays in the telemetry.
        $reportedGps = ['lat' => (float) $data['gps_lat'], 'lng' => (float) $data['gps_lng']];
        $bookerElsewhere = $ride->is_third_party && $user->id === $ride->passenger_id;
        $carGps = $bookerElsewhere && $ride->driver_id ? $this->freshDriverLocation($ride->driver_id) : null;

        if ($carGps !== null) {
            $data['gps_lat'] = $carGps['lat'];
            $data['gps_lng'] = $carGps['lng'];
        }

        $incident = DB::transaction(function () use ($ride, $user, $triggerType, $data, $vehicle, $reportedGps, $bookerElsewhere, $carGps) {
            // Serialise triggers for the same ride so a double tap cannot open
            // two incidents.
            Ride::whereKey($ride->id)->lockForUpdate()->first();

            $activeCount = SosIncident::where('ride_id', $ride->id)
                ->active()
                ->count();

            if ($activeCount >= config('sos.max_active_incidents_per_ride', 1)) {
                throw new \RuntimeException('An active SOS incident already exists for this ride.');
            }

            $incident = SosIncident::create([
                'ride_id' => $ride->id,
                'triggered_by_user_id' => $user->id,
                'trigger_type' => $triggerType,
                'status' => SosIncidentStatus::Triggered,
                'gps_lat' => $data['gps_lat'],
                'gps_lng' => $data['gps_lng'],
                'vehicle_details' => $vehicle ? [
                    'plate_number' => $vehicle->plate_number ?? null,
                    'make' => $vehicle->make ?? null,
                    'model' => $vehicle->model ?? null,
                    'color' => $vehicle->colour ?? null,
                    'vehicle_class' => $ride->vehicleClass?->name ?? null,
                ] : null,
                'telemetry_data' => [
                    'ride_status' => $ride->status->value,
                    'passenger_id' => $ride->passenger_id,
                    'driver_id' => $ride->driver_id,
                    'pickup_lat' => (float) $ride->pickup_lat,
                    'pickup_lng' => (float) $ride->pickup_lng,
                    'destination_lat' => (float) $ride->destination_lat,
                    'destination_lng' => (float) $ride->destination_lng,
                    'triggered_at_speed' => $data['speed'] ?? null,
                    'triggered_at_heading' => $data['heading'] ?? null,
                    ...($ride->is_third_party ? [
                        'third_party_rider' => [
                            'name' => $ride->rider_name,
                            'phone' => $ride->rider_phone,
                        ],
                        'triggered_by_booker' => $bookerElsewhere,
                        'reporter_gps' => $reportedGps,
                        'gps_source' => $carGps !== null ? 'driver' : 'reporter',
                    ] : []),
                ],
            ]);

            $this->logEvent($incident, 'triggered', $user, [
                'trigger_type' => $triggerType->value,
                'gps_lat' => $data['gps_lat'],
                'gps_lng' => $data['gps_lng'],
            ]);

            return $incident;
        });

        // The WhatsApp alert to the safety desk and the server-side check-in
        // timer are queued first: they must not depend on the WebSocket server.
        SendSosWhatsAppAlertJob::dispatch($incident->id)->afterCommit();

        SendSosCheckInJob::dispatch($incident->id)
            ->afterCommit()
            ->delay(now()->addSeconds(config('sos.check_in_delay_seconds', 5)));

        try {
            event(new SosIncidentTriggered(
                incidentId: $incident->id,
                rideId: $ride->id,
                triggeredByUserId: $user->id,
                triggerType: $triggerType->value,
                gpsLat: (float) $incident->gps_lat,
                gpsLng: (float) $incident->gps_lng,
                status: SosIncidentStatus::Triggered->value,
                triggeredAt: now()->toISOString(),
                referenceNumber: $incident->reference_number,
            ));
        } catch (\Throwable $e) {
            Log::error('SOS console broadcast failed; WhatsApp alert still queued', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::warning('SOS incident triggered', [
            'incident_id' => $incident->id,
            'ride_id' => $ride->id,
            'user_id' => $user->id,
            'trigger_type' => $triggerType->value,
        ]);

        return $incident;
    }

    /**
     * @return bool Whether the check-in was sent (false if the incident moved on meanwhile)
     */
    public function sendCheckIn(SosIncident $incident): bool
    {
        return DB::transaction(function () use ($incident) {
            $incident = $this->lock($incident);

            if ($incident->status !== SosIncidentStatus::Triggered) {
                return false;
            }

            $this->transitionTo($incident, SosIncidentStatus::CheckInSent);
            $incident->update(['check_in_sent_at' => now()]);

            return true;
        });
    }

    public function acknowledge(SosIncident $incident, User $user): SosIncident
    {
        return DB::transaction(function () use ($incident, $user) {
            $incident = $this->lock($incident);

            if ($incident->triggered_by_user_id !== $user->id) {
                throw new \RuntimeException('Only the person who triggered the SOS can acknowledge it.');
            }

            // "I'm safe" is accepted as soon as the SOS is raised, not only after
            // the check-in prompt arrives.
            if (! in_array($incident->status, [SosIncidentStatus::Triggered, SosIncidentStatus::CheckInSent], true)) {
                throw new \RuntimeException('This incident is not awaiting a check-in response.');
            }

            $this->transitionTo($incident, SosIncidentStatus::Acknowledged, $user);
            $incident->update(['check_in_acknowledged_at' => now()]);

            return $incident->fresh();
        });
    }

    public function cancel(SosIncident $incident, User $user): SosIncident
    {
        return DB::transaction(function () use ($incident, $user) {
            $incident = $this->lock($incident);

            if ($incident->isTerminal()) {
                throw new \RuntimeException('This incident has already been closed.');
            }

            $isParticipant = $incident->triggered_by_user_id === $user->id
                || $user->isAdmin();

            if (! $isParticipant) {
                throw new \RuntimeException('You are not authorized to cancel this incident.');
            }

            if (! $this->canTransitionTo($incident, SosIncidentStatus::Cancelled)) {
                throw new \RuntimeException('A safety operator is handling this incident and will close it.');
            }

            $this->transitionTo($incident, SosIncidentStatus::Cancelled, $user);

            return $incident->fresh();
        });
    }

    /**
     * @return bool Whether the incident was escalated (false if the user responded meanwhile)
     */
    public function escalate(SosIncident $incident): bool
    {
        $escalated = DB::transaction(function () use ($incident) {
            $incident = $this->lock($incident);

            if ($incident->status !== SosIncidentStatus::CheckInSent) {
                return null;
            }

            $this->transitionTo($incident, SosIncidentStatus::Escalated);
            $incident->update(['escalated_at' => now()]);

            return $incident;
        });

        if (! $escalated) {
            return false;
        }

        try {
            event(new SosIncidentEscalated(
                incidentId: $escalated->id,
                rideId: $escalated->ride_id,
                triggeredByUserId: $escalated->triggered_by_user_id,
                triggerType: $escalated->trigger_type->value,
                gpsLat: (float) $escalated->gps_lat,
                gpsLng: (float) $escalated->gps_lng,
                status: SosIncidentStatus::Escalated->value,
                escalatedAt: now()->toISOString(),
                referenceNumber: $escalated->reference_number,
            ));
        } catch (\Throwable $e) {
            Log::error('SOS escalation broadcast failed', [
                'incident_id' => $escalated->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::critical('SOS incident escalated — no check-in response', [
            'incident_id' => $escalated->id,
            'ride_id' => $escalated->ride_id,
        ]);

        return true;
    }

    /**
     * Take the incident, or take it over from another operator.
     *
     * When $expectedOperatorId is given (null meaning "nobody has it"), the
     * take only goes ahead if the incident is still held by that operator, so
     * two operators racing for the same alert can't silently swap it.
     *
     * @throws SosIncidentTakenException when the holder changed since the caller looked
     */
    public function assignOperator(
        SosIncident $incident,
        User $operator,
        bool $checkHolder = false,
        ?string $expectedOperatorId = null,
    ): SosIncident {
        return DB::transaction(function () use ($incident, $operator, $checkHolder, $expectedOperatorId) {
            $incident = $this->lock($incident);

            if ($incident->isTerminal()) {
                throw new \RuntimeException('This incident cannot be assigned in its current state.');
            }

            if ($checkHolder && $incident->operator_id !== $expectedOperatorId && $incident->operator_id !== $operator->id) {
                throw new SosIncidentTakenException($incident->load('operator'));
            }

            if ($incident->operator_id === $operator->id) {
                return $incident->fresh();
            }

            if ($this->canTransitionTo($incident, SosIncidentStatus::OperatorAssigned)) {
                $this->takeIncident($incident, $operator);
            } else {
                $this->logEvent($incident, 'operator_reassigned', $operator, [
                    'previous_operator_id' => $incident->operator_id,
                ]);
                $incident->update(['operator_id' => $operator->id]);
                $this->broadcastUpdate($incident, $incident->status);
            }

            return $incident->fresh();
        });
    }

    public function dispatch(SosIncident $incident, User $operator, array $data): SosIncident
    {
        return DB::transaction(function () use ($incident, $operator, $data) {
            $incident = $this->lock($incident);

            if ($incident->isTerminal()) {
                throw new \RuntimeException('This incident has already been closed.');
            }

            if ($incident->status === SosIncidentStatus::Dispatched) {
                throw new \RuntimeException('Emergency services have already been dispatched for this incident.');
            }

            // Dispatching an alert nobody has taken yet takes it first, so the
            // operator who called for help is on record as handling it.
            if ($incident->status !== SosIncidentStatus::OperatorAssigned) {
                $this->takeIncident($incident, $operator);
            }

            $this->transitionTo($incident, SosIncidentStatus::Dispatched, $operator, [
                'dispatch_notes' => $data['notes'] ?? null,
                'emergency_service_type' => $data['emergency_service_type'] ?? 'police',
                'contact_number' => $data['contact_number'] ?? null,
            ]);

            return $incident->fresh();
        });
    }

    public function resolve(SosIncident $incident, User $operator, string $notes): SosIncident
    {
        return DB::transaction(function () use ($incident, $operator, $notes) {
            $incident = $this->lock($incident);

            if ($incident->isTerminal()) {
                throw new \RuntimeException('This incident has already been closed.');
            }

            $this->transitionTo($incident, SosIncidentStatus::Resolved, $operator, [
                'resolution_notes' => $notes,
            ]);

            $incident->update([
                'operator_id' => $incident->operator_id ?? $operator->id,
                'operator_assigned_at' => $incident->operator_assigned_at ?? now(),
                'operator_notes' => $notes,
                'resolved_at' => now(),
            ]);

            return $incident->fresh();
        });
    }

    /**
     * Record that an operator spoke to (or tried to reach) someone on the
     * incident. The call itself is placed from the operator's phone.
     *
     * @param  array{called_party: string, outcome: string, phone?: string|null, notes?: string|null}  $data
     */
    public function logCall(SosIncident $incident, User $operator, array $data): SosIncident
    {
        return DB::transaction(function () use ($incident, $operator, $data) {
            $incident = $this->lock($incident);

            // Calling about an alert nobody has taken yet takes it, under the
            // same lock, like dispatch does. An alert another operator holds
            // stays with them; the call is still logged.
            if ($incident->operator_id === null && $this->canTransitionTo($incident, SosIncidentStatus::OperatorAssigned)) {
                $this->takeIncident($incident, $operator);
            }

            $this->logEvent($incident, 'operator_call', $operator, [
                'called_party' => $data['called_party'],
                'outcome' => $data['outcome'],
                'phone' => $data['phone'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            return $incident->fresh();
        });
    }

    private function takeIncident(SosIncident $incident, User $operator): void
    {
        $this->transitionTo($incident, SosIncidentStatus::OperatorAssigned, $operator);

        $incident->update([
            'operator_id' => $operator->id,
            'operator_assigned_at' => $incident->operator_assigned_at ?? now(),
        ]);
    }

    private function lock(SosIncident $incident): SosIncident
    {
        return SosIncident::lockForUpdate()->findOrFail($incident->id);
    }

    private function transitionTo(
        SosIncident $incident,
        SosIncidentStatus $newStatus,
        ?User $actor = null,
        ?array $metadata = null,
    ): void {
        if (! $this->canTransitionTo($incident, $newStatus)) {
            throw new InvalidStateTransitionException(
                "Invalid SOS incident transition from '{$incident->status->value}' to '{$newStatus->value}'.",
                $incident->status->value,
                $newStatus->value,
            );
        }

        $fromStatus = $incident->status;

        $incident->update(['status' => $newStatus]);

        $this->logEvent($incident, "transition:{$fromStatus->value}->{$newStatus->value}", $actor, $metadata);

        $this->broadcastUpdate($incident, $fromStatus);
    }

    /**
     * Tell the safety console about the change once it has committed. Like the
     * other SOS broadcasts, a failure is logged and never undoes the change.
     */
    private function broadcastUpdate(SosIncident $incident, SosIncidentStatus $previousStatus): void
    {
        DB::afterCommit(function () use ($incident, $previousStatus) {
            try {
                event(SosIncidentUpdated::fromIncident($incident->fresh(), $previousStatus));
            } catch (\Throwable $e) {
                Log::error('SOS update broadcast failed', [
                    'incident_id' => $incident->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function logEvent(
        SosIncident $incident,
        string $eventType,
        ?User $actor = null,
        ?array $metadata = null,
    ): void {
        SosEventLog::create([
            'incident_id' => $incident->id,
            'event_type' => $eventType,
            'actor_id' => $actor?->id,
            'metadata' => $metadata,
        ]);
    }
}
