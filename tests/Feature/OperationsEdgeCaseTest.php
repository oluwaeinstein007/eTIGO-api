<?php

use App\Contracts\PushNotificationGateway;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\FuelType;
use App\Enums\SosIncidentStatus;
use App\Events\SosIncidentTriggered;
use App\Jobs\ExpireEvReservationsJob;
use App\Jobs\LiftExpiredComplianceSuspensionsJob;
use App\Jobs\SendSosWhatsAppAlertJob;
use App\Jobs\TransferReservationJob;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\GamificationProfile;
use App\Models\Notification;
use App\Models\OfflineTripFlag;
use App\Models\PointMultiplierConfig;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\TierConfig;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\CarbonScoreService;
use App\Services\LedgerService;
use App\Services\SanctionService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// === SOS ===

it('lets a safety operator resolve an SOS before it auto-escalates', function () {
    $operator = User::factory()->safetyOperator()->create();
    $incident = SosIncident::factory()->create(['status' => SosIncidentStatus::Triggered]);

    $this->actingAs($operator)
        ->postJson("/api/v1/admin/sos/{$incident->id}/resolve", ['notes' => 'Called the passenger; false alarm confirmed.'])
        ->assertOk()
        ->assertJsonPath('incident.status', 'resolved');
});

it('lets a safety operator take over an SOS as soon as it is raised', function () {
    $operator = User::factory()->safetyOperator()->create();
    $incident = SosIncident::factory()->create(['status' => SosIncidentStatus::Triggered]);

    $this->actingAs($operator)
        ->postJson("/api/v1/admin/sos/{$incident->id}/assign")
        ->assertOk()
        ->assertJsonPath('incident.status', 'operator_assigned');

    expect($incident->fresh()->operator_id)->toBe($operator->id);
});

it('accepts "I am safe" before the check-in prompt is sent', function () {
    $passenger = User::factory()->passenger()->create();
    $incident = SosIncident::factory()->create([
        'triggered_by_user_id' => $passenger->id,
        'status' => SosIncidentStatus::Triggered,
    ]);

    $this->actingAs($passenger)
        ->postJson("/api/v1/sos/{$incident->id}/acknowledge")
        ->assertOk();

    expect($incident->fresh()->status)->toBe(SosIncidentStatus::Acknowledged);
});

it('still queues the WhatsApp safety alert when the SOS console broadcast fails', function () {
    Queue::fake();
    $passenger = User::factory()->passenger()->create();
    $ride = Ride::factory()->inProgress()->create(['passenger_id' => $passenger->id]);
    Event::listen(SosIncidentTriggered::class, fn () => throw new RuntimeException('WebSocket server unavailable'));

    $this->actingAs($passenger)
        ->postJson("/api/v1/rides/{$ride->id}/sos", ['gps_lat' => 6.5244, 'gps_lng' => 3.3792])
        ->assertCreated();

    Queue::assertPushed(SendSosWhatsAppAlertJob::class);
});

// === Anti-offline sanctions ===

it('reinstates a driver once the automatic L2 suspension has run its course', function () {
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspended_at' => now()->subHours(config('offline_detection.suspension_hours') + 1),
        'suspension_reason' => SanctionService::AUTOMATIC_SUSPENSION_REASON,
    ]);
    $adminSuspended = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspended_at' => now()->subDays(10),
        'suspension_reason' => 'Repeated complaints from passengers.',
    ]);

    app()->call([new LiftExpiredComplianceSuspensionsJob, 'handle']);

    expect($driver->fresh()->status)->toBe(DriverStatus::Approved)
        ->and($adminSuspended->fresh()->status)->toBe(DriverStatus::Suspended);
});

it('refuses to confirm a permanent deactivation while the driver\'s dispute is pending', function () {
    $operator = User::factory()->safetyOperator()->create();
    $driver = Driver::factory()->create(['status' => DriverStatus::Suspended]);
    $flag = OfflineTripFlag::factory()->tier3()->disputed()->create(['driver_id' => $driver->user_id]);

    $this->actingAs($operator)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation")
        ->assertUnprocessable()
        ->assertJsonPath('message', "Review the driver's dispute before confirming deactivation.");

    expect($driver->fresh()->status)->toBe(DriverStatus::Suspended);
});

// === EV charging ===

function evDriver(FuelType $fuelType = FuelType::Electric): Driver
{
    $driver = Driver::factory()->approved()->create();
    Vehicle::factory()->create(['driver_id' => $driver->id, 'fuel_type' => $fuelType]);

    return $driver;
}

function driverEarningsKobo(Driver $driver): int
{
    return (int) Account::where('owner_type', 'App\\Models\\Driver')
        ->where('owner_id', $driver->id)
        ->where('type', AccountType::DriverEarningsAvailable)
        ->value('balance');
}

function stationWithFreeStall(): EvChargingStation
{
    $station = EvChargingStation::factory()->create();
    EvChargingStall::factory()->create(['station_id' => $station->id]);

    return $station;
}

it('refuses EV charging reservations for vehicles that cannot charge', function () {
    $driver = evDriver(FuelType::Petrol);
    $station = stationWithFreeStall();

    $this->actingAs($driver->user)
        ->postJson("/api/v1/driver/ev-reservations/stations/{$station->id}/reserve")
        ->assertUnprocessable()
        ->assertJsonPath('message', "EV charging is only available for electric and plug-in hybrid vehicles. Set your vehicle's fuel type if it is one.");

    expect(EvReservation::count())->toBe(0);
});

it('charges the reservation fee to the driver\'s earnings when a stall is held', function () {
    $driver = evDriver();
    $station = stationWithFreeStall();

    $this->actingAs($driver->user)
        ->postJson("/api/v1/driver/ev-reservations/stations/{$station->id}/reserve")
        ->assertCreated()
        ->assertJsonPath('outcome', 'reserved');

    expect(driverEarningsKobo($driver))->toBe(-50000)
        ->and(app(LedgerService::class)->systemAccount(AccountType::PlatformFees)->fresh()->balance)->toBe(50000)
        ->and(EvReservation::first()->fee_charged_at)->not->toBeNull();
});

it('waives the reservation fee for Platinum and Diamond drivers', function () {
    $this->seed(ReferenceDataSeeder::class);
    $driver = evDriver();
    GamificationProfile::factory()->create(['user_id' => $driver->user_id, 'current_tier' => 4]);
    $station = stationWithFreeStall();

    $this->actingAs($driver->user)
        ->postJson("/api/v1/driver/ev-reservations/stations/{$station->id}/reserve")
        ->assertCreated()
        ->assertJsonPath('reservation.fee_waived', true);

    expect(driverEarningsKobo($driver))->toBe(0);
});

it('refunds the fee when the driver cancels within the free-cancellation window', function () {
    $driver = evDriver();
    $station = stationWithFreeStall();
    $id = $this->actingAs($driver->user)->postJson("/api/v1/driver/ev-reservations/stations/{$station->id}/reserve")->json('reservation.id');

    $this->actingAs($driver->user)->postJson("/api/v1/driver/ev-reservations/{$id}/cancel")->assertOk();

    expect(driverEarningsKobo($driver))->toBe(0)
        ->and(EvReservation::find($id)->fee_refunded_at)->not->toBeNull();
});

it('keeps the fee when the driver cancels after the free-cancellation window', function () {
    $driver = evDriver();
    $station = stationWithFreeStall();
    $id = $this->actingAs($driver->user)->postJson("/api/v1/driver/ev-reservations/stations/{$station->id}/reserve")->json('reservation.id');
    $this->travel(config('ev_charging.free_cancellation_minutes') + 1)->minutes();

    $this->actingAs($driver->user)->postJson("/api/v1/driver/ev-reservations/{$id}/cancel")->assertOk();

    expect(driverEarningsKobo($driver))->toBe(-50000)
        ->and(EvReservation::find($id)->fee_refunded_at)->toBeNull();
});

it('refuses a reservation the driver\'s earnings cannot cover and keeps the stall free', function () {
    AppSetting::setValue('wallet.max_negative_balance', 0, 'wallet');
    $driver = evDriver();
    $station = stationWithFreeStall();

    $this->actingAs($driver->user)
        ->postJson("/api/v1/driver/ev-reservations/stations/{$station->id}/reserve")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Your earnings balance cannot cover the ₦500.00 reservation fee.');

    expect(EvReservation::count())->toBe(0)
        ->and($station->stalls()->first()->status)->toBe(EvStallStatus::Available);
});

it('lets an approved driver declare an electric vehicle once but not change it afterwards', function () {
    $driver = Driver::factory()->approved()->create();
    Vehicle::factory()->create(['driver_id' => $driver->id, 'fuel_type' => null]);

    $this->actingAs($driver->user)
        ->putJson('/api/v1/driver/vehicle', ['fuel_type' => 'electric'])
        ->assertOk()
        ->assertJsonPath('vehicle.fuel_type', 'electric')
        ->assertJsonPath('vehicle.can_charge', true);

    $this->actingAs($driver->user)
        ->putJson('/api/v1/driver/vehicle', ['fuel_type' => 'petrol'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fuel_type']);
});

it('lets operations correct a driver\'s fuel type and audits it', function () {
    $operations = User::factory()->admin(AdminRole::Operations)->create();
    $driver = evDriver(FuelType::Petrol);

    $this->actingAs($operations)
        ->putJson("/api/v1/admin/drivers/{$driver->id}/vehicle-class", ['fuel_type' => 'electric'])
        ->assertOk()
        ->assertJsonPath('vehicle.fuel_type', 'electric');

    expect(AuditLog::where('auditable_id', $driver->vehicle->id)->where('event', 'vehicle_fuel_type_changed')->exists())->toBeTrue();
});

it('scores a trip in an electric vehicle as zero-emission for loyalty points', function () {
    $driver = evDriver();
    $ride = Ride::factory()->completed()->create(['driver_id' => $driver->user_id, 'pricing_snapshot' => ['distance_km' => 10.0]]);

    $score = app(CarbonScoreService::class)->calculateForRide($ride, $ride->passenger_id);

    expect((float) $score['trip_carbon_score']->vehicle_emission)->toBe(0.0)
        ->and($score['co2_saved_kg'])->toBe(1.2);
});

it('releases a reserved stall when the driver never arrives and hands it to the next in queue', function () {
    $station = EvChargingStation::factory()->create();
    $stall = EvChargingStall::factory()->reserved()->create(['station_id' => $station->id]);
    $noShow = EvReservation::factory()->create([
        'station_id' => $station->id,
        'stall_id' => $stall->id,
        'reserved_at' => now()->subMinutes(config('ev_charging.reservation_expiry_minutes') + 1),
    ]);
    $nextDriver = Driver::factory()->approved()->create();
    $next = EvReservation::factory()->queued()->create(['station_id' => $station->id, 'queue_position' => 1, 'driver_id' => $nextDriver->user_id]);

    app()->call([new ExpireEvReservationsJob, 'handle']);

    expect($noShow->fresh()->status)->toBe(EvReservationStatus::Expired)
        ->and($next->fresh()->status)->toBe(EvReservationStatus::Reserved)
        ->and($next->fresh()->stall_id)->toBe($stall->id)
        ->and($stall->fresh()->status)->toBe(EvStallStatus::Reserved)
        ->and($next->fresh()->fee_charged_at)->not->toBeNull();
});

it('skips and notifies a queued driver whose fee cannot be taken and serves the next driver', function () {
    $push = Mockery::spy(PushNotificationGateway::class);
    $this->app->instance(PushNotificationGateway::class, $push);
    $station = EvChargingStation::factory()->create();
    $stall = EvChargingStall::factory()->create(['station_id' => $station->id]);
    $onHold = Driver::factory()->approved()->create();
    app(LedgerService::class)
        ->findOrCreateAccount('App\\Models\\Driver', $onHold->id, AccountType::DriverEarningsAvailable)
        ->update(['status' => AccountStatus::Frozen]);
    $skipped = EvReservation::factory()->queued()->create(['station_id' => $station->id, 'queue_position' => 1, 'driver_id' => $onHold->user_id]);
    $nextDriver = Driver::factory()->approved()->create();
    $next = EvReservation::factory()->queued()->create(['station_id' => $station->id, 'queue_position' => 2, 'driver_id' => $nextDriver->user_id]);

    app()->call([new TransferReservationJob($station->id), 'handle']);

    expect($skipped->fresh()->status)->toBe(EvReservationStatus::Cancelled)
        ->and($next->fresh()->status)->toBe(EvReservationStatus::Reserved)
        ->and($next->fresh()->stall_id)->toBe($stall->id);
    $push->shouldHaveReceived('sendToUser')
        ->with($onHold->user_id, Mockery::on(fn (array $notification) => $notification['data']['type'] === 'ev_reservation_cancelled'))
        ->once();
});

// === Notifications, caching, reference data ===

it('prunes in-app notifications older than the retention period', function () {
    $user = User::factory()->passenger()->create();
    $old = Notification::create(['user_id' => $user->id, 'type' => 'ride_completed', 'title' => 'Old', 'body' => 'Old notice']);
    $old->forceFill(['created_at' => now()->subDays(config('notification.retention_days') + 1)])->save();
    $recent = Notification::create(['user_id' => $user->id, 'type' => 'ride_completed', 'title' => 'New', 'body' => 'New notice']);

    $this->artisan('model:prune', ['--model' => [Notification::class]])->assertSuccessful();

    expect(Notification::find($old->id))->toBeNull()
        ->and(Notification::find($recent->id))->not->toBeNull();
});

it('hides a deactivated vehicle class from the public city listing immediately', function () {
    $operations = User::factory()->admin(AdminRole::Operations)->create();
    $city = City::factory()->create();
    $vehicleClass = VehicleClass::factory()->create();
    $city->vehicleClasses()->attach($vehicleClass->id, ['is_active' => true]);
    $this->getJson("/api/v1/cities/{$city->id}/vehicle-classes")->assertJsonCount(1, 'vehicle_classes');

    $this->actingAs($operations)
        ->putJson("/api/v1/admin/vehicle-classes/{$vehicleClass->id}", ['is_active' => false])
        ->assertOk();

    $this->getJson("/api/v1/cities/{$city->id}/vehicle-classes")->assertJsonCount(0, 'vehicle_classes');
});

it('seeds reference data on every deploy without overwriting admin changes', function () {
    $this->seed(ReferenceDataSeeder::class);
    TierConfig::where('tier_level', 2)->update(['booking_fee_discount_pct' => 3.5]);
    PointMultiplierConfig::where('condition_type', 'ev_ride')->update(['multiplier_value' => 3.0]);

    $this->seed(ReferenceDataSeeder::class);

    expect((float) TierConfig::where('tier_level', 2)->value('booking_fee_discount_pct'))->toBe(3.5)
        ->and((float) PointMultiplierConfig::where('condition_type', 'ev_ride')->value('multiplier_value'))->toBe(3.0)
        ->and(TierConfig::count())->toBe(5);
});
