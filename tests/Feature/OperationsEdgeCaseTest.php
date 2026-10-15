<?php

use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\SosIncidentStatus;
use App\Events\SosIncidentTriggered;
use App\Jobs\ExpireEvReservationsJob;
use App\Jobs\LiftExpiredComplianceSuspensionsJob;
use App\Jobs\SendSosWhatsAppAlertJob;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\Notification;
use App\Models\OfflineTripFlag;
use App\Models\PointMultiplierConfig;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\TierConfig;
use App\Models\User;
use App\Models\VehicleClass;
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

it('releases a reserved stall when the driver never arrives and hands it to the next in queue', function () {
    $station = EvChargingStation::factory()->create();
    $stall = EvChargingStall::factory()->reserved()->create(['station_id' => $station->id]);
    $noShow = EvReservation::factory()->create([
        'station_id' => $station->id,
        'stall_id' => $stall->id,
        'reserved_at' => now()->subMinutes(config('ev_charging.reservation_expiry_minutes') + 1),
    ]);
    $next = EvReservation::factory()->queued()->create(['station_id' => $station->id, 'queue_position' => 1]);

    app()->call([new ExpireEvReservationsJob, 'handle']);

    expect($noShow->fresh()->status)->toBe(EvReservationStatus::Expired)
        ->and($next->fresh()->status)->toBe(EvReservationStatus::Reserved)
        ->and($next->fresh()->stall_id)->toBe($stall->id)
        ->and($stall->fresh()->status)->toBe(EvStallStatus::Reserved);
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
