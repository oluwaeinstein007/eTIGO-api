<?php

namespace App\Services;

use App\Enums\SosIncidentStatus;
use App\Enums\SosTriggerType;
use App\Events\SosIncidentEscalated;
use App\Jobs\SendSosCheckInJob;
use App\Models\Ride;
use App\Models\SosEventLog;
use App\Models\SosIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SosService
{
    private const VALID_TRANSITIONS = [
        'triggered' => [SosIncidentStatus::CheckInSent, SosIncidentStatus::Cancelled],
        'check_in_sent' => [SosIncidentStatus::Acknowledged, SosIncidentStatus::Escalated, SosIncidentStatus::Cancelled],
        'acknowledged' => [SosIncidentStatus::Resolved, SosIncidentStatus::Cancelled],
        'escalated' => [SosIncidentStatus::OperatorAssigned, SosIncidentStatus::Resolved, SosIncidentStatus::Cancelled],
        'operator_assigned' => [SosIncidentStatus::Dispatched, SosIncidentStatus::Resolved],
        'dispatched' => [SosIncidentStatus::Resolved],
        'resolved' => [],
        'cancelled' => [],
    ];

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

        $activeCount = SosIncident::where('ride_id', $ride->id)
            ->active()
            ->count();

        $maxActive = config('sos.max_active_incidents_per_ride', 1);
        if ($activeCount >= $maxActive) {
            throw new \RuntimeException('An active SOS incident already exists for this ride.');
        }

        $vehicle = null;
        if ($ride->driver_id) {
            $ride->loadMissing('driver.driver.vehicle');
            $driverRecord = $ride->driver?->driver;
            $vehicle = $driverRecord?->vehicle;
        }

        $incident = DB::transaction(function () use ($ride, $user, $triggerType, $data, $vehicle) {
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
                    'color' => $vehicle->color ?? null,
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
                ],
            ]);

            $this->logEvent($incident, 'triggered', $user, [
                'trigger_type' => $triggerType->value,
                'gps_lat' => $data['gps_lat'],
                'gps_lng' => $data['gps_lng'],
            ]);

            return $incident;
        });

        $checkInDelay = config('sos.check_in_delay_seconds', 5);
        SendSosCheckInJob::dispatch($incident->id)
            ->afterCommit()
            ->delay(now()->addSeconds($checkInDelay));

        Log::warning('SOS incident triggered', [
            'incident_id' => $incident->id,
            'ride_id' => $ride->id,
            'user_id' => $user->id,
            'trigger_type' => $triggerType->value,
        ]);

        return $incident;
    }

    public function sendCheckIn(SosIncident $incident): void
    {
        if ($incident->status !== SosIncidentStatus::Triggered) {
            return;
        }

        $this->transitionTo($incident, SosIncidentStatus::CheckInSent);
        $incident->update(['check_in_sent_at' => now()]);
    }

    public function acknowledge(SosIncident $incident, User $user): SosIncident
    {
        if ($incident->status !== SosIncidentStatus::CheckInSent) {
            throw new \RuntimeException('This incident is not awaiting a check-in response.');
        }

        if ($incident->triggered_by_user_id !== $user->id) {
            throw new \RuntimeException('Only the person who triggered the SOS can acknowledge it.');
        }

        return DB::transaction(function () use ($incident, $user) {
            $this->transitionTo($incident, SosIncidentStatus::Acknowledged, $user);
            $incident->update(['check_in_acknowledged_at' => now()]);

            return $incident->fresh();
        });
    }

    public function cancel(SosIncident $incident, User $user): SosIncident
    {
        if ($incident->isTerminal()) {
            throw new \RuntimeException('This incident has already been closed.');
        }

        $isParticipant = $incident->triggered_by_user_id === $user->id
            || $user->isAdmin();

        if (! $isParticipant) {
            throw new \RuntimeException('You are not authorized to cancel this incident.');
        }

        return DB::transaction(function () use ($incident, $user) {
            $this->transitionTo($incident, SosIncidentStatus::Cancelled, $user);

            return $incident->fresh();
        });
    }

    public function escalate(SosIncident $incident): void
    {
        if ($incident->status !== SosIncidentStatus::CheckInSent) {
            return;
        }

        DB::transaction(function () use ($incident) {
            $this->transitionTo($incident, SosIncidentStatus::Escalated);
            $incident->update(['escalated_at' => now()]);
        });

        event(new SosIncidentEscalated(
            incidentId: $incident->id,
            rideId: $incident->ride_id,
            triggeredByUserId: $incident->triggered_by_user_id,
            triggerType: $incident->trigger_type->value,
            gpsLat: (float) $incident->gps_lat,
            gpsLng: (float) $incident->gps_lng,
            status: SosIncidentStatus::Escalated->value,
            escalatedAt: now()->toISOString(),
        ));

        Log::critical('SOS incident escalated — no check-in response', [
            'incident_id' => $incident->id,
            'ride_id' => $incident->ride_id,
        ]);
    }

    public function assignOperator(SosIncident $incident, User $operator): SosIncident
    {
        if (! in_array($incident->status, [SosIncidentStatus::Escalated, SosIncidentStatus::OperatorAssigned], true)) {
            throw new \RuntimeException('This incident cannot be assigned in its current state.');
        }

        return DB::transaction(function () use ($incident, $operator) {
            $incident = SosIncident::lockForUpdate()->findOrFail($incident->id);

            if ($incident->status === SosIncidentStatus::Escalated) {
                $this->transitionTo($incident, SosIncidentStatus::OperatorAssigned, $operator);
            } else {
                $this->logEvent($incident, 'operator_reassigned', $operator, [
                    'previous_operator_id' => $incident->operator_id,
                ]);
            }

            $incident->update(['operator_id' => $operator->id]);

            return $incident->fresh();
        });
    }

    public function dispatch(SosIncident $incident, User $operator, array $data): SosIncident
    {
        if ($incident->status !== SosIncidentStatus::OperatorAssigned) {
            throw new \RuntimeException('An operator must be assigned before dispatching emergency services.');
        }

        return DB::transaction(function () use ($incident, $operator, $data) {
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
        if ($incident->isTerminal()) {
            throw new \RuntimeException('This incident has already been closed.');
        }

        return DB::transaction(function () use ($incident, $operator, $notes) {
            $this->transitionTo($incident, SosIncidentStatus::Resolved, $operator, [
                'resolution_notes' => $notes,
            ]);

            $incident->update([
                'operator_id' => $incident->operator_id ?? $operator->id,
                'operator_notes' => $notes,
                'resolved_at' => now(),
            ]);

            return $incident->fresh();
        });
    }

    private function transitionTo(
        SosIncident $incident,
        SosIncidentStatus $newStatus,
        ?User $actor = null,
        ?array $metadata = null,
    ): void {
        if (! $this->canTransitionTo($incident, $newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid SOS incident transition from '{$incident->status->value}' to '{$newStatus->value}'."
            );
        }

        $fromStatus = $incident->status;
        $incident->update(['status' => $newStatus]);

        $this->logEvent($incident, "transition:{$fromStatus->value}->{$newStatus->value}", $actor, $metadata);
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
