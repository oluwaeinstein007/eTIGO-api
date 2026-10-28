<?php

use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\FleetAgreementStatus;
use App\Enums\UserType;
use App\Events\EvReservationUpdated;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\FleetAgreement;
use App\Models\Notification;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->city = City::factory()->create();

    $this->driverUser = User::factory()->create(['type' => UserType::Driver, 'first_name' => 'Tunde', 'last_name' => 'Bello']);
    $this->driver = Driver::factory()->create(['user_id' => $this->driverUser->id, 'status' => DriverStatus::Approved]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;
    $this->vehicle = Vehicle::factory()->electric()->create(['driver_id' => $this->driver->id, 'plate_number' => 'LND-482-KJ']);

    $this->ops = User::factory()->create(['type' => UserType::Admin, 'admin_role' => AdminRole::Operations, 'first_name' => 'Ada', 'last_name' => 'Okafor']);
    $this->opsToken = $this->ops->createToken('auth', ['admin', 'operations'])->plainTextToken;

    $this->station = EvChargingStation::factory()->create(['city_id' => $this->city->id, 'total_stalls' => 1]);
    $this->stall = EvChargingStall::factory()->create(['station_id' => $this->station->id, 'stall_number' => 1]);
});

/** Each request authenticates afresh, so a test can switch between driver and operations. */
function evAs($test, string $token)
{
    app('auth')->forgetGuards();

    return $test->withToken($token);
}

function reserveStall($test): EvReservation
{
    $id = evAs($test, $test->driverToken)
        ->postJson("/api/v1/driver/ev-reservations/stations/{$test->station->id}/reserve")
        ->assertCreated()
        ->json('reservation.id');

    return EvReservation::findOrFail($id);
}

it('lists every reservation, instant and requested, with filters', function () {
    $instant = reserveStall($this);
    $request = EvReservation::factory()->chargeRequest()->create(['station_id' => $this->station->id]);

    evAs($this, $this->opsToken)
        ->getJson('/api/v1/admin/ev-reservations')
        ->assertOk()
        ->assertJsonCount(2, 'reservations')
        ->assertJsonPath('summary.reserved', 1)
        ->assertJsonPath('summary.pending_review', 1);

    evAs($this, $this->opsToken)
        ->getJson('/api/v1/admin/ev-reservations?kind=instant')
        ->assertOk()
        ->assertJsonCount(1, 'reservations')
        ->assertJsonPath('reservations.0.id', $instant->id)
        ->assertJsonPath('reservations.0.kind', 'instant');

    evAs($this, $this->opsToken)
        ->getJson('/api/v1/admin/ev-reservations?kind=request&status=pending_review')
        ->assertOk()
        ->assertJsonCount(1, 'reservations')
        ->assertJsonPath('reservations.0.id', $request->id)
        ->assertJsonPath('reservations.0.kind', 'request');

    evAs($this, $this->opsToken)
        ->getJson('/api/v1/admin/ev-reservations?search=482-KJ')
        ->assertOk()
        ->assertJsonCount(1, 'reservations')
        ->assertJsonPath('reservations.0.id', $instant->id);
});

it('refuses an unknown status filter and other admin roles', function () {
    evAs($this, $this->opsToken)
        ->getJson('/api/v1/admin/ev-reservations?status=parked')
        ->assertUnprocessable();

    $finance = User::factory()->create(['type' => UserType::Admin, 'admin_role' => AdminRole::Finance]);

    evAs($this, $finance->createToken('auth', ['admin', 'finance'])->plainTextToken)
        ->getJson('/api/v1/admin/ev-reservations')
        ->assertForbidden();
});

it('cancels a reservation, frees the stall, refunds when asked and tells the driver', function () {
    $reservation = reserveStall($this);

    $this->travel(10)->minutes();

    evAs($this, $this->opsToken)
        ->postJson("/api/v1/admin/ev-reservations/{$reservation->id}/cancel", [
            'reason' => 'Charger 1 is faulty',
            'refund_fee' => true,
        ])
        ->assertOk()
        ->assertJsonPath('fee_refunded', true)
        ->assertJsonPath('reservation.status.value', 'cancelled')
        ->assertJsonPath('reservation.end_reason', 'Charger 1 is faulty')
        ->assertJsonPath('reservation.ended_by_operations', true)
        ->assertJsonPath('reservation.ended_by.name', 'Ada Okafor');

    expect($reservation->fresh()->fee_refunded_at)->not->toBeNull()
        ->and($this->stall->fresh()->status)->toBe(EvStallStatus::Available)
        ->and(Notification::where('user_id', $this->driverUser->id)->latest('created_at')->value('body'))
        ->toContain('cancelled by E-tiGo Operations: Charger 1 is faulty.')
        ->toContain('has been refunded');

    // The driver sees why, not who.
    evAs($this, $this->driverToken)
        ->getJson("/api/v1/driver/ev-reservations/{$reservation->id}")
        ->assertJsonPath('reservation.end_reason', 'Charger 1 is faulty')
        ->assertJsonMissingPath('reservation.ended_by');
});

it('keeps the fee when cancelling without a refund', function () {
    $reservation = reserveStall($this);

    evAs($this, $this->opsToken)
        ->postJson("/api/v1/admin/ev-reservations/{$reservation->id}/cancel", ['reason' => 'Driver did not show up'])
        ->assertOk()
        ->assertJsonPath('fee_refunded', false);

    expect($reservation->fresh()->fee_refunded_at)->toBeNull();
});

it('ends a charging session the driver did not finish', function () {
    $reservation = reserveStall($this);
    evAs($this, $this->driverToken)->postJson("/api/v1/driver/ev-reservations/{$reservation->id}/activate")->assertOk();

    evAs($this, $this->opsToken)
        ->postJson("/api/v1/admin/ev-reservations/{$reservation->id}/end", ['reason' => 'Car left the bay'])
        ->assertOk()
        ->assertJsonPath('reservation.status.value', 'completed')
        ->assertJsonPath('reservation.end_reason', 'Car left the bay');

    expect($this->stall->fresh()->status)->toBe(EvStallStatus::Available)
        ->and(Notification::where('user_id', $this->driverUser->id)->latest('created_at')->value('type'))
        ->toBe('ev_reservation_ended');
});

it('refuses to end a request or a finished reservation', function () {
    $request = EvReservation::factory()->chargeRequest()->create(['station_id' => $this->station->id]);
    $done = EvReservation::factory()->completed()->create(['station_id' => $this->station->id]);

    evAs($this, $this->opsToken)
        ->postJson("/api/v1/admin/ev-reservations/{$request->id}/end")
        ->assertUnprocessable()
        ->assertJsonPath('current_status', 'pending_review');

    evAs($this, $this->opsToken)
        ->postJson("/api/v1/admin/ev-reservations/{$done->id}/cancel", ['reason' => 'Too late now'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This reservation has already ended.');
});

it('cancels a queued driver and moves the rest of the line up', function () {
    $first = EvReservation::factory()->queued()->create(['station_id' => $this->station->id, 'queue_position' => 1]);
    $second = EvReservation::factory()->queued()->create(['station_id' => $this->station->id, 'queue_position' => 2]);

    evAs($this, $this->opsToken)
        ->postJson("/api/v1/admin/ev-reservations/{$first->id}/cancel", ['reason' => 'Station closing early'])
        ->assertOk();

    expect($second->fresh()->queue_position)->toBe(1);
});

it('tells the driver app on its own channel when a reservation changes', function () {
    Event::fake([EvReservationUpdated::class]);

    $reservation = reserveStall($this);

    Event::assertDispatched(EvReservationUpdated::class, fn (EvReservationUpdated $e) => $e->reservationId === $reservation->id
        && $e->driverUserId === $this->driverUser->id
        && $e->broadcastOn()[0]->name === "private-driver.{$this->driverUser->id}");

    $payload = (new EvReservationUpdated($reservation->id, $this->driverUser->id))->broadcastWith();

    expect($payload['reservation']['status']['value'])->toBe(EvReservationStatus::Reserved->value)
        ->and($payload['reservation'])->not->toHaveKey('ended_by');
});

it('charges no fee while the driver is still paying off an E-tiGo car', function (FleetAgreementStatus $status) {
    FleetAgreement::factory()->create(['driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id, 'status' => $status]);

    $reservation = reserveStall($this);

    expect($reservation->fee_waived)->toBeTrue()
        ->and($reservation->fee_waiver_reason)->toBe('hire_purchase')
        ->and((float) $reservation->fee_amount)->toBe(0.0)
        ->and($reservation->fee_charged_at)->toBeNull();

    evAs($this, $this->driverToken)
        ->getJson('/api/v1/driver/vehicle/status')
        ->assertJsonPath('charging.reservation_fee', ['amount_kobo' => 0, 'waived' => true, 'waiver_reason' => 'hire_purchase']);
})->with([FleetAgreementStatus::Active, FleetAgreementStatus::Paused]);

it('charges the fee once the car is paid off, and to drivers with their own car', function (?FleetAgreementStatus $status) {
    if ($status !== null) {
        FleetAgreement::factory()->create(['driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id, 'status' => $status]);
    }

    $reservation = reserveStall($this);

    expect($reservation->fee_waived)->toBeFalse()
        ->and($reservation->fee_waiver_reason)->toBeNull()
        ->and($reservation->fee_charged_at)->not->toBeNull();

    evAs($this, $this->driverToken)
        ->getJson('/api/v1/driver/vehicle/status')
        ->assertJsonPath('charging.reservation_fee', ['amount_kobo' => 50000, 'waived' => false, 'waiver_reason' => null]);
})->with(['paid off' => FleetAgreementStatus::Completed, 'terminated' => FleetAgreementStatus::Terminated, 'own car' => null]);

it('waives the fee on a confirmed charge request for a hire-purchase driver', function () {
    FleetAgreement::factory()->create(['driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id]);
    $request = EvReservation::factory()->chargeRequest()->create(['driver_id' => $this->driverUser->id, 'station_id' => $this->station->id]);

    evAs($this, $this->opsToken)
        ->postJson("/api/v1/admin/ev-charge-requests/{$request->id}/confirm", ['confirmed_slot_at' => now()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('reservation.status.value', 'reserved')
        ->assertJsonPath('reservation.fee_waived', true)
        ->assertJsonPath('reservation.fee_waiver_reason', 'hire_purchase')
        ->assertJsonPath('reservation.fee_amount_kobo', 0);
});

it('lets operations change the EV settings, which apply to the next reservation and show in /config', function () {
    evAs($this, $this->opsToken)
        ->putJson('/api/v1/admin/settings/ev-charging', ['reservation_fee' => 750, 'reservation_expiry_minutes' => 45, 'free_cancellation_minutes' => 5])
        ->assertOk()
        ->assertJsonPath('settings.reservation_fee', 750)
        ->assertJsonPath('settings.reservation_expiry_minutes', 45);

    $this->getJson('/api/v1/config')
        ->assertJsonPath('ev_charging.reservation_fee_kobo', 75000)
        ->assertJsonPath('ev_charging.check_in_minutes', 45)
        ->assertJsonPath('ev_charging.free_cancellation_minutes', 5)
        ->assertJsonPath('vehicle_maintenance.legal_grace_days', 7);

    $reservation = reserveStall($this);

    expect((float) $reservation->fee_amount)->toBe(750.0);

    evAs($this, $this->driverToken)
        ->getJson("/api/v1/driver/ev-reservations/{$reservation->id}")
        ->assertJsonPath('reservation.check_in_deadline', $reservation->reserved_at->copy()->addMinutes(45)->toISOString())
        ->assertJsonPath('reservation.free_cancellation_until', $reservation->fresh()->fee_charged_at->copy()->addMinutes(5)->toISOString());
});

it('can stop counting a paused hire-purchase agreement, or switch the waiver off', function (array $settings, FleetAgreementStatus $status, bool $waived) {
    FleetAgreement::factory()->create(['driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id, 'status' => $status]);

    evAs($this, $this->opsToken)->putJson('/api/v1/admin/settings/ev-charging', $settings)->assertOk();

    expect(reserveStall($this)->fee_waived)->toBe($waived);
})->with([
    'paused no longer counts' => [['waive_fee_while_agreement_paused' => false], FleetAgreementStatus::Paused, false],
    'active still counts' => [['waive_fee_while_agreement_paused' => false], FleetAgreementStatus::Active, true],
    'waiver off' => [['hire_purchase_fee_waiver' => false], FleetAgreementStatus::Active, false],
]);

it('refunds the fee when ending a session if operations choose to', function () {
    $reservation = reserveStall($this);
    evAs($this, $this->driverToken)->postJson("/api/v1/driver/ev-reservations/{$reservation->id}/activate")->assertOk();
    $this->travel(30)->minutes();

    evAs($this, $this->opsToken)
        ->postJson("/api/v1/admin/ev-reservations/{$reservation->id}/end", ['reason' => 'Charger failed mid-session', 'refund_fee' => true])
        ->assertOk()
        ->assertJsonPath('fee_refunded', true)
        ->assertJsonPath('reservation.status.value', 'completed');

    expect(Notification::where('user_id', $this->driverUser->id)->latest('created_at')->value('body'))->toContain('has been refunded');
});

it('validates the EV settings and keeps them for operations', function () {
    evAs($this, $this->opsToken)
        ->putJson('/api/v1/admin/settings/ev-charging', ['reservation_expiry_minutes' => 1, 'reservation_fee' => -5])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reservation_expiry_minutes', 'reservation_fee']);

    $finance = User::factory()->create(['type' => UserType::Admin, 'admin_role' => AdminRole::Finance]);

    evAs($this, $finance->createToken('auth', ['admin', 'finance'])->plainTextToken)
        ->getJson('/api/v1/admin/settings/ev-charging')
        ->assertForbidden();
});
