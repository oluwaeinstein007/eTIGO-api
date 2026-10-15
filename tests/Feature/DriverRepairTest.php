<?php

use App\Enums\DriverStatus;
use App\Enums\RepairRequestStatus;
use App\Enums\RepairServiceType;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverRepair;
use App\Models\User;
use App\Models\Vehicle;

beforeEach(function () {
    $this->city = City::factory()->create();

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->create([
        'user_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'status' => DriverStatus::Approved,
        'is_online' => true,
    ]);
    $this->vehicle = Vehicle::factory()->create([
        'driver_id' => $this->driver->id,
        'make' => 'BYD',
        'model' => 'Dolphin',
        'colour' => 'Pearl White',
        'plate_number' => 'LAG 482 EV',
    ]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->otherDriverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->otherDriver = Driver::factory()->create([
        'user_id' => $this->otherDriverUser->id,
        'city_id' => $this->city->id,
        'status' => DriverStatus::Approved,
    ]);
    $this->otherDriverToken = $this->otherDriverUser->createToken('auth', ['driver'])->plainTextToken;
});

describe('Driver Repair & Maintenance Request Submission', function () {
    it('requires authentication', function () {
        $this->postJson('/api/v1/driver/repairs', [
            'type' => 'repair',
            'title' => 'Brake noise',
            'details' => 'Squeaking noise when stopping',
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_time' => '12:00 pm',
        ])->assertUnauthorized();
    });

    it('allows driver to submit a repair request', function () {
        $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
            ->postJson('/api/v1/driver/repairs', [
                'type' => 'repair',
                'title' => 'Front brake noise',
                'details' => 'Squeaking sound coming from front left brake when coming to a halt.',
                'preferred_date' => now()->addDays(2)->toDateString(),
                'preferred_time' => '12:00 pm',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Vehicle service request submitted successfully.')
            ->assertJsonPath('repair.title', 'Front brake noise')
            ->assertJsonPath('repair.type.value', 'repair')
            ->assertJsonPath('repair.type.label', 'Repair')
            ->assertJsonPath('repair.status.value', 'in_progress')
            ->assertJsonPath('repair.vehicle_name', 'BYD Dolphin')
            ->assertJsonPath('repair.vehicle_plate', 'LAG 482 EV')
            ->assertJsonPath('repair.vehicle_color', 'Pearl White')
            ->assertJsonPath('repair.preferred_time', '12:00 pm')
            ->assertJsonPath('repair.workshop_visit.scheduled_time', '12:00 pm')
            ->assertJsonCount(4, 'repair.progress_steps');

        $this->assertDatabaseHas('driver_repairs', [
            'driver_id' => $this->driverUser->id,
            'title' => 'Front brake noise',
            'type' => 'repair',
            'status' => 'in_progress',
        ]);
    });

    it('gives each request its own reference even when two are submitted at the same moment', function () {
        $referenceDrawnByOtherRequest = DriverRepair::generateReferenceNumber();

        $reference = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
            ->postJson('/api/v1/driver/repairs', [
                'type' => 'repair',
                'title' => 'Front brake noise',
                'details' => 'Squeaking sound coming from front left brake when coming to a halt.',
                'preferred_date' => now()->addDays(2)->toDateString(),
                'preferred_time' => '12:00 pm',
            ])
            ->assertCreated()
            ->json('repair.reference_number');

        expect($reference)->toStartWith('REP-')->not->toBe($referenceDrawnByOtherRequest);
    });

    it('allows driver to submit a maintenance request', function () {
        $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
            ->postJson('/api/v1/driver/repairs', [
                'type' => 'maintenance',
                'title' => 'Routine maintenance',
                'details' => 'Scheduled 20,000km vehicle check and tyre rotation.',
                'preferred_date' => now()->addDays(5)->toDateString(),
                'preferred_time' => '10:00 am',
            ]);

        $response->assertCreated()
            ->assertJsonPath('repair.title', 'Routine maintenance')
            ->assertJsonPath('repair.type.value', 'maintenance')
            ->assertJsonPath('repair.type.label', 'Maintenance');
    });

    it('validates required fields', function () {
        $this->withHeader('Authorization', "Bearer {$this->driverToken}")
            ->postJson('/api/v1/driver/repairs', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'title', 'details', 'preferred_date', 'preferred_time']);
    });

    it('validates request type must be repair or maintenance', function () {
        $this->withHeader('Authorization', "Bearer {$this->driverToken}")
            ->postJson('/api/v1/driver/repairs', [
                'type' => 'invalid_service',
                'title' => 'Oil change',
                'details' => 'Details',
                'preferred_date' => now()->toDateString(),
                'preferred_time' => '12:00 pm',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });
});

describe('Driver Repair Listing and Detail Retrieval', function () {
    it('lists repair requests for authenticated driver', function () {
        DriverRepair::create([
            'driver_id' => $this->driverUser->id,
            'vehicle_id' => $this->vehicle->id,
            'reference_number' => 'REP-1048',
            'type' => RepairServiceType::Repair,
            'status' => RepairRequestStatus::InProgress,
            'title' => 'Front brake noise',
            'details' => 'Squeaking brake',
            'preferred_date' => now()->toDateString(),
            'preferred_time' => '12:00 pm',
        ]);

        DriverRepair::create([
            'driver_id' => $this->otherDriverUser->id,
            'reference_number' => 'REP-1049',
            'type' => RepairServiceType::Maintenance,
            'status' => RepairRequestStatus::Scheduled,
            'title' => 'Other driver request',
            'details' => 'Maintenance',
            'preferred_date' => now()->toDateString(),
            'preferred_time' => '02:00 pm',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
            ->getJson('/api/v1/driver/repairs');

        $response->assertOk()
            ->assertJsonCount(1, 'repairs')
            ->assertJsonPath('repairs.0.title', 'Front brake noise')
            ->assertJsonPath('repairs.0.reference_number', 'REP-1048');
    });

    it('returns detail of a single repair request', function () {
        $repair = DriverRepair::create([
            'driver_id' => $this->driverUser->id,
            'vehicle_id' => $this->vehicle->id,
            'reference_number' => 'REP-1048',
            'type' => RepairServiceType::Repair,
            'status' => RepairRequestStatus::InProgress,
            'title' => 'Front brake noise',
            'details' => 'Squeaking brake',
            'preferred_date' => now()->toDateString(),
            'preferred_time' => '12:00 pm',
            'summary_note' => 'Technician assigned · Workshop scheduled for 12:00 pm',
            'workshop_visit' => [
                'scheduled_time' => '12:00 pm',
                'instructions' => 'Technician assigned.',
                'support_phone' => '0812 5690 6790',
            ],
            'progress_steps' => [
                ['title' => 'Request submitted', 'subtitle' => '10 Oct 2026', 'state' => 'completed'],
            ],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
            ->getJson("/api/v1/driver/repairs/{$repair->id}");

        $response->assertOk()
            ->assertJsonPath('repair.id', $repair->id)
            ->assertJsonPath('repair.title', 'Front brake noise')
            ->assertJsonPath('repair.workshop_visit.support_phone', '0812 5690 6790')
            ->assertJsonPath('repair.vehicle_name', 'BYD Dolphin');
    });

    it('prevents driver from viewing another driver repair request', function () {
        $repair = DriverRepair::create([
            'driver_id' => $this->otherDriverUser->id,
            'reference_number' => 'REP-1099',
            'type' => RepairServiceType::Repair,
            'status' => RepairRequestStatus::InProgress,
            'title' => 'Private other repair',
            'details' => 'Details',
            'preferred_date' => now()->toDateString(),
            'preferred_time' => '12:00 pm',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->driverToken}")
            ->getJson("/api/v1/driver/repairs/{$repair->id}")
            ->assertForbidden();
    });
});
