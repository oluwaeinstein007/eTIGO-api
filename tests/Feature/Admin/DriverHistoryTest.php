<?php

use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverHistoryService;

beforeEach(function () {
    $this->operations = User::factory()->admin(AdminRole::Operations)->create(['first_name' => 'Ada', 'last_name' => 'Obi']);
    $this->lagos = City::factory()->create(['name' => 'Lagos']);
    $this->standard = VehicleClass::factory()->create(['name' => 'Standard']);
});

it('shows city moves, vehicle class, fuel type and company car changes newest first', function () {
    $abuja = City::factory()->create(['name' => 'Abuja']);
    $premium = VehicleClass::factory()->create(['name' => 'Premium']);
    $driver = Driver::factory()->approved()->create(['city_id' => $this->lagos->id]);
    $vehicle = Vehicle::factory()->create(['driver_id' => $driver->id, 'vehicle_class_id' => $this->standard->id, 'fuel_type' => 'petrol', 'is_fleet' => false]);

    $this->actingAs($this->operations)->putJson("/api/v1/admin/drivers/{$driver->id}/city", ['city_id' => $abuja->id, 'reason' => 'Relocated'])->assertOk();
    $this->travel(1)->minute();
    $this->actingAs($this->operations)->putJson("/api/v1/admin/drivers/{$driver->id}/vehicle-class", ['vehicle_class_id' => $premium->id])->assertOk();
    $this->travel(1)->minute();
    $this->actingAs($this->operations)->putJson("/api/v1/admin/drivers/{$driver->id}/vehicle-class", ['fuel_type' => 'electric'])->assertOk();
    $this->travel(1)->minute();
    $this->actingAs($this->operations)->patchJson("/api/v1/admin/drivers/{$driver->id}/vehicle/fleet")->assertOk();

    $history = $this->actingAs($this->operations)->getJson("/api/v1/admin/drivers/{$driver->id}")
        ->assertOk()
        ->json('history');

    $actor = ['id' => $this->operations->id, 'name' => 'Ada Obi', 'role' => 'operations'];

    expect($history)->toHaveCount(4)
        ->and(collect($history)->pluck('event')->all())->toBe(['vehicle_marked_fleet', 'vehicle_fuel_type_changed', 'vehicle_class_changed', 'driver_city_changed'])
        ->and($history[0])->toMatchArray([
            'description' => 'Marked as a company car',
            'old' => ['is_fleet' => false],
            'new' => ['is_fleet' => true],
            'reason' => null,
            'actor' => $actor,
        ])
        ->and($history[1])->toMatchArray([
            'description' => 'Fuel type changed from Petrol to Electric',
            'old' => ['fuel_type' => 'petrol'],
            'new' => ['fuel_type' => 'electric'],
        ])
        ->and($history[2])->toMatchArray([
            'description' => 'Vehicle class changed from Standard to Premium',
            'old' => ['vehicle_class' => ['id' => $this->standard->id, 'name' => 'Standard']],
            'new' => ['vehicle_class' => ['id' => $premium->id, 'name' => 'Premium']],
        ])
        ->and($history[3])->toMatchArray([
            'description' => 'City changed from Lagos to Abuja',
            'old' => ['city' => ['id' => $this->lagos->id, 'name' => 'Lagos']],
            'new' => ['city' => ['id' => $abuja->id, 'name' => 'Abuja']],
            'reason' => 'Relocated',
            'document' => null,
            'actor' => $actor,
        ])
        ->and($history[3]['created_at'])->not->toBeNull();

    expect(AuditLog::where('auditable_id', $vehicle->id)->where('event', 'vehicle_marked_fleet')->value('new_values'))
        ->toBe(['is_fleet' => true, 'driver_id' => $driver->id]);
});

it('keeps a shared car\'s changes with the driver they were made for', function () {
    $premium = VehicleClass::factory()->create(['name' => 'Premium']);
    $first = Driver::factory()->approved()->create(['city_id' => $this->lagos->id]);
    $second = Driver::factory()->approved()->create(['city_id' => $this->lagos->id]);
    $car = Vehicle::factory()->create(['driver_id' => $first->id, 'vehicle_class_id' => $this->standard->id, 'is_fleet' => true]);

    $this->actingAs($this->operations)->putJson("/api/v1/admin/drivers/{$first->id}/vehicle-class", ['vehicle_class_id' => $premium->id])->assertOk();

    // Handed over to the second driver.
    $car->update(['driver_id' => $second->id]);

    expect(app(DriverHistoryService::class)->for($first))->toHaveCount(1)
        ->and(app(DriverHistoryService::class)->for($second))->toBe([]);
});

it('shows reviews with the document and reason, and fills in values older entries did not record', function () {
    $driver = Driver::factory()->create(['status' => DriverStatus::PendingReview, 'city_id' => $this->lagos->id]);
    $vehicle = Vehicle::factory()->create(['driver_id' => $driver->id, 'vehicle_class_id' => $this->standard->id]);
    $licence = DriverDocument::create([
        'driver_id' => $driver->id, 'type' => 'driving_licence', 'file_path' => 'documents/licence.pdf',
        'original_filename' => 'licence.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1024, 'status' => 'pending',
    ]);

    // Recorded before entries carried their values; the system made the
    // second change.
    AuditLog::record($driver, 'application_submitted', $driver->user);
    $this->travel(1)->minute();
    AuditLog::record($vehicle, 'vehicle_marked_fleet');
    // Not part of the approval history.
    AuditLog::record($driver, 'driver_went_online', $driver->user);
    $this->travel(1)->minute();

    $this->actingAs($this->operations)->postJson("/api/v1/admin/drivers/{$driver->id}/documents/{$licence->id}/review", [
        'action' => 'reject',
        'rejection_reason' => 'Photo is blurry',
    ])->assertOk();

    $history = $this->actingAs($this->operations)->getJson("/api/v1/admin/drivers/{$driver->id}")->json('history');

    expect(collect($history)->pluck('event')->all())->toBe(['driver_rejected', 'document_rejected', 'vehicle_marked_fleet', 'application_submitted'])
        ->and($history[0])->toMatchArray([
            'description' => 'Rejected',
            'old' => ['status' => 'pending_review'],
            'new' => ['status' => 'rejected'],
            'reason' => 'One or more documents were rejected. Please re-upload and resubmit.',
        ])
        ->and($history[1])->toMatchArray([
            'description' => 'Driving licence rejected',
            'old' => ['status' => 'pending'],
            'new' => ['status' => 'rejected'],
            'reason' => 'Photo is blurry',
            'document' => ['id' => $licence->id, 'type' => 'driving_licence', 'type_label' => 'Driving licence'],
        ])
        ->and($history[2])->toMatchArray([
            'old' => ['is_fleet' => false],
            'new' => ['is_fleet' => true],
            'actor' => null,
        ])
        ->and($history[3])->toMatchArray([
            'description' => 'Application submitted',
            'old' => null,
            'new' => ['status' => 'pending_review'],
            'actor' => ['id' => $driver->user_id, 'name' => trim($driver->user->first_name.' '.$driver->user->last_name), 'role' => 'driver'],
        ]);

    // A deleted paper's entry still shows, from the type it recorded.
    $licence->delete();

    expect(app(DriverHistoryService::class)->for($driver)[1]['document'])
        ->toBe(['id' => $licence->id, 'type' => 'driving_licence', 'type_label' => 'Driving licence']);
});

it('returns at most the latest 50 entries', function () {
    $driver = Driver::factory()->approved()->create(['city_id' => $this->lagos->id]);

    foreach (range(1, DriverHistoryService::LIMIT + 1) as $i) {
        AuditLog::record($driver, $i % 2 ? 'driver_suspended' : 'driver_reactivated', $this->operations);
    }

    expect(app(DriverHistoryService::class)->for($driver))->toHaveCount(DriverHistoryService::LIMIT);
});
