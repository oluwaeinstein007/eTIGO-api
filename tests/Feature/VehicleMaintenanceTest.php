<?php

use App\Contracts\MapsGateway;
use App\Enums\AdminRole;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\FuelType;
use App\Enums\MaintenanceItemStatus;
use App\Enums\MaintenanceItemType;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\ProcessVehicleMaintenanceJob;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Notification;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleMaintenanceItem;
use App\Notifications\OperationsAlertNotification;
use App\Services\DriverLocationService;
use App\Services\DriverMatchingService;
use App\Services\RideService;
use App\Services\VehicleMaintenanceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake(config('filesystems.uploads'));
    // Monday 19 Oct 2026, 08:00 UTC (09:00 in Lagos).
    $this->travelTo(Carbon::parse('2026-10-19 08:00:00', 'UTC'));

    CommissionConfig::factory()->create(['rate' => 0.2000]);

    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->city = City::factory()->create(['currency_code' => 'NGN', 'timezone' => 'Africa/Lagos']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);
    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $this->admin->id,
    ]);

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getDistanceAndDuration')->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $maps->shouldReceive('reverseGeocode')->andReturn(['address' => '123 Test Street, Lagos']);
    $this->app->instance(MapsGateway::class, $maps);

    $this->nearby = [];
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('getDriverLocation')->andReturn(['lat' => 6.52, 'lng' => 3.38, 'heading' => 0, 'speed' => 0, 'timestamp' => now()->timestamp]);
    $locations->shouldReceive('findNearbyDrivers')->andReturnUsing(fn () => $this->nearby);
    $locations->shouldReceive('removeDriver')->andReturnNull();
    $this->app->instance(DriverLocationService::class, $locations);

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    // A driver with their own petrol car.
    $this->user = User::factory()->create(['type' => UserType::Driver, 'first_name' => 'Bayo']);
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->user->id, 'city_id' => $this->city->id]);
    $this->vehicle = Vehicle::factory()->create(['driver_id' => $this->driver->id, 'vehicle_class_id' => $this->vehicleClass->id]);
    $this->token = $this->user->createToken('auth', ['driver'])->plainTextToken;

    $this->paper = fn (Vehicle $vehicle, DocumentType $type, ?string $expiresAt, DocumentStatus $status = DocumentStatus::Approved) => DriverDocument::create([
        'driver_id' => $vehicle->driver_id,
        'vehicle_id' => $vehicle->id,
        'type' => $type,
        'file_path' => 'driver-documents/test.pdf',
        'original_filename' => 'paper.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1000,
        'expires_at' => $expiresAt,
        'status' => $status,
        'reviewed_at' => $status === DocumentStatus::Approved ? now() : null,
    ]);

    $this->item = fn (Vehicle $vehicle, MaintenanceItemType $type) => VehicleMaintenanceItem::where('vehicle_id', $vehicle->id)->where('type', $type)->first();
    $this->runJob = fn () => app()->call([new ProcessVehicleMaintenanceJob, 'handle']);
});

// === ITEMS BY FUEL TYPE ===

it('gives every car the general items, and plug-in cars battery health too', function () {
    $types = fn (Vehicle $vehicle) => VehicleMaintenanceItem::where('vehicle_id', $vehicle->id)->current()->pluck('type')->map->value->sort()->values()->all();

    expect($types($this->vehicle))->toBe(['insurance', 'roadworthiness', 'service', 'tyres_brakes', 'vehicle_licence']);

    $ev = Vehicle::factory()->electric()->create();
    $hybrid = Vehicle::factory()->create(['fuel_type' => FuelType::Hybrid]);

    expect($types($ev))->toContain('battery_health')
        ->and($types($hybrid))->not->toContain('battery_health');

    // Correcting the fuel type adds or archives battery health.
    $this->vehicle->update(['fuel_type' => FuelType::PlugInHybrid]);
    expect($types($this->vehicle))->toContain('battery_health');

    $this->vehicle->update(['fuel_type' => FuelType::Petrol]);
    expect($types($this->vehicle))->not->toContain('battery_health')
        ->and(VehicleMaintenanceItem::where('vehicle_id', $this->vehicle->id)->where('type', 'battery_health')->whereNotNull('archived_at')->exists())->toBeTrue();
});

it('shows the driver their car status, starting with no records', function () {
    ($this->as)($this->token)->getJson('/api/v1/driver/vehicle/status')
        ->assertOk()
        ->assertJsonPath('can_drive', true)
        ->assertJsonPath('records_managed_by', 'driver')
        ->assertJsonPath('charging', null)
        ->assertJsonCount(5, 'items')
        ->assertJsonPath('items.0.type', 'service')
        ->assertJsonPath('items.0.status', 'unknown')
        ->assertJsonPath('items.0.message', 'Add the date of your last Routine service.');
});

// === RECORDS ===

it('lets the driver submit a service with proof, and operations approve it', function () {
    ($this->as)($this->token)->post('/api/v1/driver/vehicle/maintenance-records', [
        'item_type' => 'service',
        'performed_at' => '2026-09-01',
        'odometer_km' => 42000,
        'workshop' => 'Mainland Motors',
        'proof' => UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('record.status', 'pending');

    // Still unknown until approved.
    expect(($this->item)($this->vehicle, MaintenanceItemType::Service)->status)->toBe(MaintenanceItemStatus::Unknown);

    $recordId = ($this->as)($this->adminToken)->getJson('/api/v1/admin/vehicle-maintenance/records')
        ->assertOk()
        ->assertJsonCount(1, 'records')
        ->json('records.0.id');

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/vehicle-maintenance/records/{$recordId}/review", ['action' => 'approve'])
        ->assertOk();

    $service = ($this->item)($this->vehicle, MaintenanceItemType::Service);
    expect($service->status)->toBe(MaintenanceItemStatus::Ok)
        ->and($service->due_at->toDateString())->toBe('2026-12-01')
        ->and($service->due_odometer_km)->toBe(47000)
        ->and($this->vehicle->fresh()->odometer_km)->toBe(42000)
        ->and(Notification::where('user_id', $this->user->id)->where('type', 'maintenance_record_reviewed')->exists())->toBeTrue();
});

it('needs proof from drivers, and refuses drivers of fleet cars and odometer readings that go down', function () {
    ($this->as)($this->token)->postJson('/api/v1/driver/vehicle/maintenance-records', [
        'item_type' => 'service',
        'performed_at' => '2026-09-01',
        'odometer_km' => 42000,
    ])->assertStatus(422)->assertJsonValidationErrors('proof');

    ($this->as)($this->token)->postJson('/api/v1/driver/vehicle/odometer', ['odometer_km' => 50000])->assertOk();
    ($this->as)($this->token)->postJson('/api/v1/driver/vehicle/odometer', ['odometer_km' => 49000])
        ->assertStatus(422)
        ->assertJsonValidationErrors('odometer_km');

    // A service last month can't show more km than today's reading.
    ($this->as)($this->token)->post('/api/v1/driver/vehicle/maintenance-records', [
        'item_type' => 'service',
        'performed_at' => '2026-09-01',
        'odometer_km' => 51000,
        'proof' => UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('odometer_km');

    // Battery health is for plug-in cars only.
    ($this->as)($this->token)->post('/api/v1/driver/vehicle/maintenance-records', [
        'item_type' => 'battery_health',
        'performed_at' => '2026-09-01',
        'proof' => UploadedFile::fake()->create('report.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('item_type');

    $this->vehicle->update(['is_fleet' => true]);
    ($this->as)($this->token)->post('/api/v1/driver/vehicle/maintenance-records', [
        'item_type' => 'tyres_brakes',
        'performed_at' => '2026-09-01',
        'proof' => UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertForbidden();
});

it('makes a service due by kilometres driven on trips, and overdue by date', function () {
    ($this->as)($this->adminToken)->postJson("/api/v1/admin/vehicles/{$this->vehicle->id}/maintenance-records", [
        'item_type' => 'service',
        'performed_at' => '2026-10-01',
        'odometer_km' => 10000,
    ])->assertCreated();

    expect(($this->item)($this->vehicle, MaintenanceItemType::Service)->status)->toBe(MaintenanceItemStatus::Ok);

    // 4,600 km of trips since: within 500 km of the 15,000 km service.
    app(VehicleMaintenanceService::class)->addTripDistance($this->user->id, 4600);
    $items = app(VehicleMaintenanceService::class)->refresh($this->vehicle->fresh());

    expect($items->firstWhere('type', MaintenanceItemType::Service)->status)->toBe(MaintenanceItemStatus::DueSoon);

    // Three months on: overdue whatever the distance. Overdue only warns.
    $this->travelTo(Carbon::parse('2027-01-03 08:00:00', 'UTC'));
    $items = app(VehicleMaintenanceService::class)->refresh($this->vehicle->fresh());

    expect($items->firstWhere('type', MaintenanceItemType::Service)->status)->toBe(MaintenanceItemStatus::Overdue)
        ->and($this->vehicle->fresh()->isBlocked())->toBeFalse();
});

it('counts a completed trip towards the service interval', function () {
    $this->vehicle->update(['odometer_km' => 20000, 'odometer_read_at' => now()->subDay()]);
    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->user->id,
        'status' => RideStatus::InProgress,
        'pricing_snapshot' => ['distance_km' => 12.4, 'duration_minutes' => 20, 'pricing_config_id' => PricingConfig::first()->id],
    ]);

    app(RideService::class)->completeRide($ride, $this->user);

    expect((float) $this->vehicle->fresh()->trip_km_since_odometer)->toBeGreaterThan(0.0)
        ->and($this->vehicle->fresh()->estimatedOdometerKm())->toBeGreaterThan(20000);
});

// === LEGAL PAPERS ===

it('warns during the 7-day grace period, then keeps the car off the road', function () {
    $insurance = ($this->paper)($this->vehicle, DocumentType::InsuranceCertificate, '2026-10-13');

    // Expired six days ago: a warning, still allowed online.
    ($this->as)($this->token)->postJson('/api/v1/driver/toggle-online')
        ->assertOk()
        ->assertJsonPath('vehicle_notice', 'Insurance expired on 13 Oct 2026; upload the renewed paper by 20 Oct 2026 to keep driving.');

    expect(($this->item)($this->vehicle, MaintenanceItemType::Insurance)->status)->toBe(MaintenanceItemStatus::Expired)
        ->and($this->vehicle->fresh()->isBlocked())->toBeFalse();

    // The driver also holds a reserved scheduled pickup.
    $rideId = ($this->as)($this->passengerToken)->postJson('/api/v1/rides', [
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'pickup_address' => '123 Test Street',
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
        'destination_address' => '456 Dest Street',
        'payment_method' => 'cash',
        'scheduled_pickup_at' => '2026-10-22T07:00:00Z',
    ])->assertCreated()->json('ride.id');
    ($this->as)($this->token)->postJson("/api/v1/driver/scheduled-rides/{$rideId}/reserve")->assertOk();

    // 21 Oct: the grace period is over. The daily run blocks the car, sets
    // the idle driver offline and releases the reservation.
    $this->travelTo(Carbon::parse('2026-10-21 05:00:00', 'UTC'));
    ($this->runJob)();

    $vehicle = $this->vehicle->fresh();
    expect($vehicle->isBlocked())->toBeTrue()
        ->and($vehicle->block_reason)->toBe('Insurance expired on 13 Oct 2026.')
        ->and($this->driver->fresh()->is_online)->toBeFalse()
        ->and(ScheduledRide::where('ride_id', $rideId)->value('reserved_driver_id'))->toBeNull()
        ->and(ScheduledRide::where('ride_id', $rideId)->value('reservation_released_reason'))->toBe('vehicle_blocked')
        ->and(Notification::where('user_id', $this->user->id)->where('type', 'vehicle_blocked')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->passenger->id)->where('type', 'scheduled_ride_reservation_released')->exists())->toBeTrue();

    ($this->as)($this->token)->postJson('/api/v1/driver/toggle-online')
        ->assertStatus(422)
        ->assertJsonPath('reasons.0', 'Insurance expired on 13 Oct 2026. Upload the renewed paper; you can go online once E-tiGo approves it.');

    // A renewal awaiting review does not lift the block, and the old paper stays.
    ($this->as)($this->token)->post('/api/v1/driver/documents', [
        'type' => 'insurance_certificate',
        'document' => UploadedFile::fake()->create('insurance.pdf', 200, 'application/pdf'),
        'expires_at' => '2027-10-23',
    ], ['Accept' => 'application/json'])->assertCreated();

    $renewal = DriverDocument::where('vehicle_id', $this->vehicle->id)->where('status', DocumentStatus::Pending)->firstOrFail();
    expect($insurance->fresh())->not->toBeNull();
    ($this->as)($this->token)->postJson('/api/v1/driver/toggle-online')->assertStatus(422);

    // Approved: the old paper is replaced and the car can drive again.
    $adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;
    ($this->as)($adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$renewal->id}/review", ['action' => 'approve'])
        ->assertOk();

    expect($this->vehicle->fresh()->isBlocked())->toBeFalse()
        ->and($insurance->fresh())->toBeNull()
        ->and(Notification::where('user_id', $this->user->id)->where('type', 'vehicle_unblocked')->exists())->toBeTrue();

    ($this->as)($this->token)->postJson('/api/v1/driver/toggle-online')->assertOk()->assertJsonPath('vehicle_notice', null);
});

it('never ends a trip when the block starts, and offers the driver nothing new', function () {
    ($this->paper)($this->vehicle, DocumentType::VehicleLicence, '2026-10-01');
    $this->driver->update(['is_online' => true]);
    Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->user->id,
        'status' => RideStatus::InProgress,
    ]);

    ($this->runJob)();

    expect($this->vehicle->fresh()->isBlocked())->toBeTrue()
        ->and($this->driver->fresh()->is_online)->toBeTrue();

    // Not offered rides, nor assignable by operations.
    $otherUser = User::factory()->create(['type' => UserType::Driver]);
    $other = Driver::factory()->online()->create(['user_id' => $otherUser->id, 'city_id' => $this->city->id]);
    Vehicle::factory()->create(['driver_id' => $other->id, 'vehicle_class_id' => $this->vehicleClass->id]);
    $this->nearby = [['driver_id' => $this->driver->id, 'distance_km' => 1.0], ['driver_id' => $other->id, 'distance_km' => 2.0]];

    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => User::factory()->create(['type' => UserType::Passenger])->id,
        'status' => RideStatus::Searching,
    ]);

    expect(collect(app(DriverMatchingService::class)->findEligibleDrivers($ride, 5))->pluck('driver_id')->all())->toBe([$other->id]);

    Ride::where('driver_id', $this->user->id)->update(['status' => RideStatus::Completed]);
    ($this->as)($this->adminToken)->postJson("/api/v1/admin/rides/{$ride->id}/assign", ['driver_id' => $this->user->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('driver_id');
});

it('reminds the driver once per change and sends operations one digest of fleet cars', function () {
    NotificationFacade::fake();
    $fleet = Vehicle::factory()->fleet()->create(['plate_number' => 'FLT-001AA']);
    ($this->paper)($fleet, DocumentType::RoadworthinessCertificate, '2026-10-10');
    ($this->paper)($this->vehicle, DocumentType::InsuranceCertificate, '2026-10-25');

    ($this->runJob)();
    ($this->runJob)();

    $reminders = Notification::where('user_id', $this->user->id)->where('type', 'vehicle_maintenance_due');
    expect($reminders->count())->toBe(1)
        ->and($reminders->value('body'))->toContain('Insurance expires on 25 Oct 2026; upload the renewed paper.');

    // One digest (and, the paper being past its grace period, one "off the road" alert).
    $digests = NotificationFacade::sent($this->admin, OperationsAlertNotification::class, fn ($n) => $n->label === 'Fleet maintenance');
    expect($digests)->toHaveCount(1)
        ->and(collect($digests->first()->lines)->contains(fn ($line) => str_starts_with($line, 'FLT-001AA: Roadworthiness certificate expired on 10 Oct 2026')))->toBeTrue()
        ->and(NotificationFacade::sent($this->admin, OperationsAlertNotification::class, fn ($n) => $n->label === 'Fleet car off the road'))->toHaveCount(1);
});

it('requires an expiry date for roadworthiness and vehicle licence uploads', function () {
    ($this->as)($this->token)->post('/api/v1/driver/documents', [
        'type' => 'roadworthiness_certificate',
        'document' => UploadedFile::fake()->create('rw.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('expires_at');

    ($this->as)($this->token)->post('/api/v1/driver/documents', [
        'type' => 'roadworthiness_certificate',
        'document' => UploadedFile::fake()->create('rw.pdf', 200, 'application/pdf'),
        'expires_at' => '2027-06-30',
    ], ['Accept' => 'application/json'])->assertCreated();

    expect(DriverDocument::where('type', 'roadworthiness_certificate')->value('vehicle_id'))->toBe($this->vehicle->id);
});

it('lets the reviewer correct the expiry date when approving', function () {
    $document = ($this->paper)($this->vehicle, DocumentType::InsuranceCertificate, null, DocumentStatus::Pending);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$document->id}/review", [
        'action' => 'approve',
        'expires_at' => '2027-03-31',
    ])->assertOk();

    $insurance = ($this->item)($this->vehicle, MaintenanceItemType::Insurance);
    expect($insurance->status)->toBe(MaintenanceItemStatus::Ok)
        ->and($insurance->due_at->toDateString())->toBe('2027-03-31');
});

// === OPERATIONS ===

it('lets operations upload a fleet car\'s papers and record its maintenance', function () {
    $fleet = Vehicle::factory()->fleet()->create(['driver_id' => null]);

    ($this->as)($this->adminToken)->post("/api/v1/admin/vehicles/{$fleet->id}/documents", [
        'type' => 'vehicle_licence',
        'document' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        'expires_at' => '2027-01-31',
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.status', 'approved');

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/vehicles/{$fleet->id}/maintenance-records", [
        'item_type' => 'tyres_brakes',
        'performed_at' => '2026-10-10',
    ])->assertCreated()->assertJsonPath('record.status', 'approved');

    ($this->as)($this->adminToken)->getJson("/api/v1/admin/vehicles/{$fleet->id}/maintenance")
        ->assertOk()
        ->assertJsonPath('vehicle.is_blocked', false)
        ->assertJsonCount(1, 'documents')
        ->assertJsonCount(1, 'records')
        ->assertJsonFragment(['type' => 'vehicle_licence', 'status' => 'ok', 'due_at' => '2027-01-31'])
        ->assertJsonFragment(['type' => 'tyres_brakes', 'status' => 'ok', 'due_at' => '2027-04-10']);
});

it('lists items needing attention across the fleet', function () {
    ($this->paper)($this->vehicle, DocumentType::InsuranceCertificate, '2026-10-01');
    $fleet = Vehicle::factory()->fleet()->create();
    ($this->paper)($fleet, DocumentType::InsuranceCertificate, '2026-10-25');
    ($this->runJob)();

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/vehicle-maintenance')
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.status', 'expired')
        ->assertJsonPath('items.0.vehicle.is_blocked', true)
        ->assertJsonPath('items.1.status', 'due_soon')
        ->assertJsonPath('summary.blocked_vehicles', 1);

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/vehicle-maintenance?fleet_only=1')
        ->assertOk()
        ->assertJsonCount(1, 'items');
});

it('links vehicle papers when the car is added, and lets operations link the rest', function () {
    $user = User::factory()->create(['type' => UserType::Driver]);
    $driver = Driver::factory()->create(['user_id' => $user->id, 'city_id' => $this->city->id]);
    $token = $user->createToken('auth', ['driver'])->plainTextToken;

    // Uploaded during onboarding, before the car.
    ($this->as)($token)->post('/api/v1/driver/documents', [
        'type' => 'insurance_certificate',
        'document' => UploadedFile::fake()->create('insurance.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/vehicle-documents/unlinked')
        ->assertOk()
        ->assertJsonCount(1, 'documents');

    ($this->as)($token)->postJson('/api/v1/driver/vehicle', [
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'Silver',
        'plate_number' => 'ABC-123XY',
        'year' => 2020,
        'fuel_type' => 'petrol',
        'vehicle_class_id' => $this->vehicleClass->id,
    ])->assertCreated();

    expect(DriverDocument::where('driver_id', $driver->id)->value('vehicle_id'))->toBe($driver->vehicle()->value('id'));

    // An old paper with no car is linked by hand.
    $orphan = DriverDocument::create([
        'driver_id' => $this->driver->id,
        'type' => DocumentType::VehicleRegistration,
        'file_path' => 'driver-documents/old.pdf',
        'original_filename' => 'old.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1000,
        'status' => DocumentStatus::Approved,
    ]);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/vehicle-documents/{$orphan->id}/link", ['vehicle_id' => $this->vehicle->id])
        ->assertOk();

    expect($orphan->fresh()->vehicle_id)->toBe($this->vehicle->id);
});

it('records the charge level of plug-in cars when going online', function () {
    $this->vehicle->update(['fuel_type' => FuelType::Electric]);

    ($this->as)($this->token)->postJson('/api/v1/driver/toggle-online', ['battery_level_percent' => 140])->assertStatus(422);
    ($this->as)($this->token)->postJson('/api/v1/driver/toggle-online', ['battery_level_percent' => 64])->assertOk();

    ($this->as)($this->token)->getJson('/api/v1/driver/vehicle/status')
        ->assertOk()
        ->assertJsonPath('charging.battery_level_percent', 64)
        ->assertJsonPath('charging.active_reservation', null)
        ->assertJsonFragment(['type' => 'battery_health']);
});

it('lets operations change the intervals, per fuel type too', function () {
    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/vehicle-maintenance', [
        'intervals' => ['service' => ['km' => 7500, 'months' => 4]],
        'fuel_type_intervals' => ['electric' => ['service' => ['km' => 15000, 'months' => 12]]],
        'legal_grace_days' => 3,
    ])->assertOk()
        ->assertJsonPath('settings.intervals.service', ['km' => 7500, 'months' => 4])
        ->assertJsonPath('settings.intervals.tyres_brakes', ['km' => null, 'months' => 6])
        ->assertJsonPath('settings.fuel_type_intervals.electric.service.km', 15000)
        ->assertJsonPath('settings.legal_grace_days', 3);

    $ev = Vehicle::factory()->electric()->create();
    $service = app(VehicleMaintenanceService::class);
    expect($service->interval($ev, MaintenanceItemType::Service, AppSetting::vehicleMaintenance()))->toBe(['km' => 15000, 'months' => 12])
        ->and($service->interval($this->vehicle, MaintenanceItemType::Service, AppSetting::vehicleMaintenance()))->toBe(['km' => 7500, 'months' => 4]);

    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/vehicle-maintenance', [
        'intervals' => ['service' => ['km' => null, 'months' => null]],
    ])->assertStatus(422);
    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/vehicle-maintenance', [
        'intervals' => ['insurance' => ['months' => 12]],
    ])->assertStatus(422);
});
