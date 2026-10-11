<?php

use App\Enums\AdminRole;
use App\Enums\SosIncidentStatus;
use App\Enums\SosTriggerType;
use App\Enums\UserType;
use App\Jobs\SendSosCheckInJob;
use App\Jobs\SosEscalationTimeoutJob;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->safetyOperator = User::factory()->create([
        'type' => UserType::Admin,
        'admin_role' => AdminRole::SafetyOperator,
    ]);
    $this->operatorToken = $this->safetyOperator->createToken('auth', ['admin', 'safety_operator'])->plainTextToken;

    $this->superAdmin = User::factory()->create([
        'type' => UserType::Admin,
        'admin_role' => AdminRole::SuperAdmin,
    ]);
    $this->superAdminToken = $this->superAdmin->createToken('auth', ['admin', 'super_admin'])->plainTextToken;

    $this->activeRide = Ride::factory()->inProgress()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
    ]);
});

describe('POST /rides/{ride}/sos — Trigger SOS', function () {
    it('allows passenger to trigger SOS on active ride', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->activeRide->id}/sos",
            [
                'gps_lat' => 9.0579,
                'gps_lng' => 7.4951,
            ],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(201)
            ->assertJsonPath('incident.status', 'triggered')
            ->assertJsonPath('incident.trigger_type', 'passenger')
            ->assertJsonPath('message', 'SOS triggered. A check-in will be sent shortly.');

        $this->assertDatabaseHas('sos_incidents', [
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
            'trigger_type' => 'passenger',
            'status' => 'triggered',
        ]);

        Queue::assertPushed(SendSosCheckInJob::class);
    });

    it('allows driver to trigger SOS on active ride', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->activeRide->id}/sos",
            [
                'gps_lat' => 9.0579,
                'gps_lng' => 7.4951,
            ],
            ['Authorization' => "Bearer {$this->driverToken}"],
        );

        $response->assertStatus(201)
            ->assertJsonPath('incident.trigger_type', 'driver');
    });

    it('rejects SOS on non-active ride', function () {
        $completedRide = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->postJson(
            "/api/v1/rides/{$completedRide->id}/sos",
            ['gps_lat' => 9.0579, 'gps_lng' => 7.4951],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('ride');
    });

    it('rejects SOS from non-participant', function () {
        $otherUser = User::factory()->create(['type' => UserType::Passenger]);
        $otherToken = $otherUser->createToken('auth', ['passenger'])->plainTextToken;

        $response = $this->postJson(
            "/api/v1/rides/{$this->activeRide->id}/sos",
            ['gps_lat' => 9.0579, 'gps_lng' => 7.4951],
            ['Authorization' => "Bearer {$otherToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('ride');
    });

    it('rejects duplicate active SOS on same ride', function () {
        SosIncident::factory()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
            'status' => SosIncidentStatus::Triggered,
        ]);

        $response = $this->postJson(
            "/api/v1/rides/{$this->activeRide->id}/sos",
            ['gps_lat' => 9.0579, 'gps_lng' => 7.4951],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(409);
    });

    it('validates GPS coordinates', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->activeRide->id}/sos",
            ['gps_lat' => 999, 'gps_lng' => -999],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['gps_lat', 'gps_lng']);
    });

    it('creates audit trail event log on trigger', function () {
        $this->postJson(
            "/api/v1/rides/{$this->activeRide->id}/sos",
            ['gps_lat' => 9.0579, 'gps_lng' => 7.4951],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $this->assertDatabaseHas('sos_event_log', [
            'event_type' => 'triggered',
        ]);
    });
});

describe('POST /sos/{incident}/acknowledge', function () {
    it('allows triggering user to acknowledge check-in', function () {
        $incident = SosIncident::factory()->checkInSent()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/sos/{$incident->id}/acknowledge",
            [],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(200)
            ->assertJsonPath('incident.status', 'acknowledged')
            ->assertJsonPath('message', 'Check-in acknowledged. Glad you are safe.');
    });

    it('rejects acknowledge from non-triggering user', function () {
        $incident = SosIncident::factory()->checkInSent()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/sos/{$incident->id}/acknowledge",
            [],
            ['Authorization' => "Bearer {$this->driverToken}"],
        );

        $response->assertStatus(422);
    });

    it('rejects acknowledge on non-check-in-sent status', function () {
        $incident = SosIncident::factory()->escalated()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/sos/{$incident->id}/acknowledge",
            [],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422);
    });
});

describe('POST /sos/{incident}/cancel', function () {
    it('allows triggering user to cancel SOS', function () {
        $incident = SosIncident::factory()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
            'status' => SosIncidentStatus::Triggered,
        ]);

        $response = $this->postJson(
            "/api/v1/sos/{$incident->id}/cancel",
            [],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(200)
            ->assertJsonPath('incident.status', 'cancelled');

        $this->assertDatabaseHas('sos_event_log', [
            'incident_id' => $incident->id,
            'event_type' => 'transition:triggered->cancelled',
        ]);
    });

    it('rejects cancel on already resolved incident', function () {
        $incident = SosIncident::factory()->resolved()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/sos/{$incident->id}/cancel",
            [],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422);
    });
});

describe('Admin SOS Endpoints', function () {
    it('lists active incidents prioritized by severity', function () {
        SosIncident::factory()->escalated()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
        ]);

        $ride2 = Ride::factory()->inProgress()->create();
        SosIncident::factory()->create([
            'ride_id' => $ride2->id,
            'triggered_by_user_id' => $ride2->passenger_id,
            'status' => SosIncidentStatus::Triggered,
        ]);

        $response = $this->getJson(
            '/api/v1/admin/sos/active',
            ['Authorization' => "Bearer {$this->operatorToken}"],
        );

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 2);

        $statuses = collect($response->json('incidents'))->pluck('status')->all();
        expect($statuses[0])->toBe('escalated');
    });

    it('shows incident detail with event logs', function () {
        $incident = SosIncident::factory()->escalated()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->getJson(
            "/api/v1/admin/sos/{$incident->id}",
            ['Authorization' => "Bearer {$this->operatorToken}"],
        );

        $response->assertStatus(200)
            ->assertJsonPath('incident.id', $incident->id)
            ->assertJsonStructure([
                'incident' => ['id', 'status', 'trigger_type', 'gps_lat', 'gps_lng', 'vehicle_details', 'telemetry_data'],
            ]);
    });

    it('allows operator self-assignment', function () {
        $incident = SosIncident::factory()->escalated()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/sos/{$incident->id}/assign",
            [],
            ['Authorization' => "Bearer {$this->operatorToken}"],
        );

        $response->assertStatus(200)
            ->assertJsonPath('incident.status', 'operator_assigned');

        $this->assertDatabaseHas('sos_incidents', [
            'id' => $incident->id,
            'operator_id' => $this->safetyOperator->id,
        ]);
    });

    it('allows dispatch after operator assignment', function () {
        $incident = SosIncident::factory()->operatorAssigned()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
            'operator_id' => $this->safetyOperator->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/sos/{$incident->id}/dispatch",
            [
                'emergency_service_type' => 'police',
                'contact_number' => '112',
                'notes' => 'Dispatching police to location.',
            ],
            ['Authorization' => "Bearer {$this->operatorToken}"],
        );

        $response->assertStatus(200)
            ->assertJsonPath('incident.status', 'dispatched');
    });

    it('rejects dispatch without operator assignment', function () {
        $incident = SosIncident::factory()->escalated()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/sos/{$incident->id}/dispatch",
            ['emergency_service_type' => 'police'],
            ['Authorization' => "Bearer {$this->operatorToken}"],
        );

        $response->assertStatus(422);
    });

    it('allows resolution with notes', function () {
        $incident = SosIncident::factory()->operatorAssigned()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
            'operator_id' => $this->safetyOperator->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/sos/{$incident->id}/resolve",
            ['notes' => 'Situation resolved. Both parties confirmed safe. False alarm.'],
            ['Authorization' => "Bearer {$this->operatorToken}"],
        );

        $response->assertStatus(200)
            ->assertJsonPath('incident.status', 'resolved');

        $this->assertDatabaseHas('sos_incidents', [
            'id' => $incident->id,
            'status' => 'resolved',
            'operator_notes' => 'Situation resolved. Both parties confirmed safe. False alarm.',
        ]);
    });

    it('rejects resolution with notes too short', function () {
        $incident = SosIncident::factory()->operatorAssigned()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
            'operator_id' => $this->safetyOperator->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/sos/{$incident->id}/resolve",
            ['notes' => 'OK'],
            ['Authorization' => "Bearer {$this->operatorToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('notes');
    });

    it('returns incident history with filters', function () {
        SosIncident::factory()->resolved()->create([
            'ride_id' => $this->activeRide->id,
            'triggered_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->getJson(
            '/api/v1/admin/sos/history?status=resolved',
            ['Authorization' => "Bearer {$this->operatorToken}"],
        );

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    });

    it('denies non-safety-operator access', function () {
        $supportAdmin = User::factory()->create([
            'type' => UserType::Admin,
            'admin_role' => AdminRole::Support,
        ]);
        $token = $supportAdmin->createToken('auth', ['admin', 'support'])->plainTextToken;

        $response = $this->getJson(
            '/api/v1/admin/sos/active',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    });

    it('allows super admin access', function () {
        $response = $this->getJson(
            '/api/v1/admin/sos/active',
            ['Authorization' => "Bearer {$this->superAdminToken}"],
        );

        $response->assertStatus(200);
    });
});

describe('SOS Escalation Pipeline', function () {
    it('dispatches check-in job on trigger', function () {
        $this->postJson(
            "/api/v1/rides/{$this->activeRide->id}/sos",
            ['gps_lat' => 9.0579, 'gps_lng' => 7.4951],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        Queue::assertPushed(SendSosCheckInJob::class, function ($job) {
            return $job->incidentId !== null;
        });
    });
});
