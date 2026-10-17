<?php

use App\Enums\SosIncidentStatus;
use App\Events\SosIncidentUpdated;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Services\SosService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->operator = User::factory()->safetyOperator()->create();
    $this->token = $this->operator->createToken('auth', ['admin'])->plainTextToken;

    $this->passenger = User::factory()->passenger()->create(['first_name' => 'Amaka', 'last_name' => 'Obi', 'phone' => '+2348011112222']);
    $this->driver = User::factory()->driver()->create();
    $this->ride = Ride::factory()->inProgress()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driver->id,
    ]);

    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('getDriverLocation')->andReturn([
        'lat' => 6.5, 'lng' => 3.4, 'heading' => 90.0, 'speed' => 12.0, 'accuracy' => 8.0, 'timestamp' => now()->timestamp,
    ])->byDefault();
    $this->app->instance(DriverLocationService::class, $locations);
});

function sosIncident(array $attributes = [], ?string $state = null): SosIncident
{
    $factory = SosIncident::factory();

    if ($state) {
        $factory = $factory->{$state}();
    }

    return $factory->create([
        'ride_id' => test()->ride->id,
        'triggered_by_user_id' => test()->passenger->id,
        ...$attributes,
    ]);
}

it('gives each incident a readable reference', function () {
    $first = sosIncident();
    $second = sosIncident(['ride_id' => Ride::factory()->inProgress()->create()->id]);

    expect($first->reference_number)->toMatch('/^SOS-\d{6}$/')
        ->and($second->reference_number)->not->toBe($first->reference_number);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/sos/{$first->id}")
        ->assertJsonPath('incident.reference_number', $first->reference_number);
});

it('maps incident statuses onto the console queue status', function (string $state, string $queueStatus) {
    $incident = sosIncident(state: $state === 'triggered' ? null : $state);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/sos/{$incident->id}")
        ->assertJsonPath('incident.queue_status.value', $queueStatus);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/sos/history?queue_status={$queueStatus}")
        ->assertJsonPath('incidents.0.id', $incident->id);
})->with([
    ['triggered', 'unacknowledged'],
    ['acknowledged', 'unacknowledged'],
    ['escalated', 'unacknowledged'],
    ['operatorAssigned', 'acknowledged'],
    ['resolved', 'resolved'],
    ['cancelled', 'resolved'],
]);

it('labels a passenger "I\'m safe" as confirmed safe', function () {
    $incident = sosIncident(state: 'acknowledged');

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/sos/{$incident->id}")
        ->assertJsonPath('incident.status', 'acknowledged')
        ->assertJsonPath('incident.status_label', 'Confirmed Safe');
});

it('loads the passenger and driver on the history list', function () {
    sosIncident(state: 'resolved');

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/sos/history')
        ->assertOk()
        ->assertJsonPath('incidents.0.ride.passenger.first_name', 'Amaka')
        ->assertJsonPath('incidents.0.ride.driver.id', $this->driver->id);
});

it('searches by reference, ride reference, name and phone', function () {
    $incident = sosIncident();
    $otherRide = Ride::factory()->inProgress()->create();
    sosIncident(['ride_id' => $otherRide->id, 'triggered_by_user_id' => $otherRide->passenger_id]);

    foreach ([$incident->reference_number, $this->ride->reference_number, 'amaka obi', '2222'] as $term) {
        $this->withToken($this->token)
            ->getJson('/api/v1/admin/sos/active?search='.urlencode($term))
            ->assertOk()
            ->assertJsonCount(1, 'incidents')
            ->assertJsonPath('incidents.0.id', $incident->id);
    }
});

it('measures how long an alert waited for an operator', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00'));
    $incident = sosIncident(['created_at' => now()->subSeconds(90)], 'escalated');

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/sos/{$incident->id}")
        ->assertJsonPath('incident.wait_seconds', 90);

    Carbon::setTestNow(Carbon::parse('2026-10-17 10:01:00'));
    $this->withToken($this->token)->postJson("/api/v1/admin/sos/{$incident->id}/assign")->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-10-17 10:30:00'));
    $this->withToken($this->token)
        ->getJson("/api/v1/admin/sos/{$incident->id}")
        ->assertJsonPath('incident.operator_assigned_at', '2026-10-17T10:01:00.000000Z')
        ->assertJsonPath('incident.wait_seconds', 150);

    Carbon::setTestNow();
});

it('says when an unanswered check-in will escalate', function () {
    config(['sos.escalation_timeout_seconds' => 30]);
    $sentAt = now()->subSeconds(12)->startOfSecond();
    $incident = sosIncident(['check_in_sent_at' => $sentAt], 'checkInSent');

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/sos/{$incident->id}")
        ->assertJsonPath('incident.escalates_at', $sentAt->copy()->addSeconds(30)->toISOString());

    $taken = sosIncident(['ride_id' => Ride::factory()->inProgress()->create()->id], 'operatorAssigned');
    $this->withToken($this->token)
        ->getJson("/api/v1/admin/sos/{$taken->id}")
        ->assertJsonPath('incident.escalates_at', null);
});

it('describes each audit trail step and who took it', function () {
    $incident = sosIncident(state: 'escalated');

    $this->withToken($this->token)->postJson("/api/v1/admin/sos/{$incident->id}/assign")->assertOk();
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/sos/{$incident->id}/dispatch", ['emergency_service_type' => 'ambulance'])
        ->assertOk();

    $logs = collect($this->withToken($this->token)->getJson("/api/v1/admin/sos/{$incident->id}")->json('incident.event_logs'));

    expect($logs->pluck('description')->all())->toBe([
        'Alert taken by an operator',
        'Emergency services dispatched (ambulance)',
    ])->and($logs->pluck('actor_type')->unique()->all())->toBe(['operator']);
});

it('marks automatic steps as system', function () {
    $incident = sosIncident();
    app(SosService::class)->sendCheckIn($incident);

    $log = $this->withToken($this->token)->getJson("/api/v1/admin/sos/{$incident->id}")->json('incident.event_logs.0');

    expect($log['actor_type'])->toBe('system')
        ->and($log['description'])->toBe('Safety check-in sent');
});

it('returns the route and the driver position for the map', function () {
    $incident = sosIncident(state: 'escalated');

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/sos/{$incident->id}")
        ->assertOk()
        ->assertJsonStructure(['route' => ['encoded_polyline', 'distance_km', 'duration_minutes']])
        ->assertJsonPath('driver_location.lat', 6.5)
        ->assertJsonPath('driver_location.lng', 3.4);
});

it('logs a call to the passenger in the audit trail', function () {
    $incident = sosIncident(state: 'escalated');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/sos/{$incident->id}/calls", [
            'called_party' => 'passenger',
            'outcome' => 'no_answer',
            'phone' => $this->passenger->phone,
        ])
        ->assertCreated()
        ->assertJsonPath('incident.event_logs.0.event_type', 'operator_call')
        ->assertJsonPath('incident.event_logs.0.description', 'Called the passenger: no answer')
        ->assertJsonPath('incident.event_logs.0.actor_id', $this->operator->id);
});

it('requires a call outcome', function () {
    $incident = sosIncident();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/sos/{$incident->id}/calls", ['outcome' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('outcome');
});

it('broadcasts every status change to the console', function () {
    Event::fake([SosIncidentUpdated::class]);
    $incident = sosIncident(state: 'escalated');

    $this->withToken($this->token)->postJson("/api/v1/admin/sos/{$incident->id}/assign")->assertOk();
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/sos/{$incident->id}/resolve", ['notes' => 'Passenger reached and safe at home.'])
        ->assertOk();

    Event::assertDispatched(SosIncidentUpdated::class, fn (SosIncidentUpdated $e) => $e->status === SosIncidentStatus::OperatorAssigned->value
        && $e->previousStatus === 'escalated'
        && $e->queueStatus === 'acknowledged'
        && $e->referenceNumber === $incident->reference_number);
    Event::assertDispatched(SosIncidentUpdated::class, fn (SosIncidentUpdated $e) => $e->status === 'resolved'
        && $e->queueStatus === 'resolved');
});

it('broadcasts the check-in so the console can start the countdown', function () {
    Event::fake([SosIncidentUpdated::class]);
    $incident = sosIncident();

    app(SosService::class)->sendCheckIn($incident);

    Event::assertDispatched(SosIncidentUpdated::class, fn (SosIncidentUpdated $e) => $e->status === 'check_in_sent'
        && $e->escalatesAt !== null
        && $e->broadcastAs() === 'sos.incident.updated');
});

it('rejects an unknown status filter', function () {
    $this->withToken($this->token)
        ->getJson('/api/v1/admin/sos/history?status=on_fire')
        ->assertUnprocessable();
});
