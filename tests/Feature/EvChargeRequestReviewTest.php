<?php

use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\EvStationStatus;
use App\Enums\UserType;
use App\Jobs\ProcessEvChargeRequestsJob;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\Notification;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EvChargeRequestService;
use App\Services\EvReservationFeeService;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(now('Africa/Lagos')->setTime(8, 0));

    $this->city = City::factory()->create();

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->create([
        'user_id' => $this->driverUser->id,
        'status' => DriverStatus::Approved,
    ]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;
    Vehicle::factory()->electric()->create(['driver_id' => $this->driver->id]);

    $this->ops = User::factory()->create(['type' => UserType::Admin, 'admin_role' => AdminRole::Operations]);
    $this->opsToken = $this->ops->createToken('auth', ['admin', 'operations'])->plainTextToken;

    $this->station = EvChargingStation::factory()->create(['city_id' => $this->city->id, 'total_stalls' => 2]);
    foreach ([1, 2] as $number) {
        EvChargingStall::factory()->create(['station_id' => $this->station->id, 'stall_number' => $number]);
    }

    $this->request = EvReservation::factory()->chargeRequest()->create([
        'driver_id' => $this->driverUser->id,
        'station_id' => $this->station->id,
    ]);
});

function confirmChargeRequest($test, string $slotAt): TestResponse
{
    return $test->withToken($test->opsToken)
        ->postJson("/api/v1/admin/ev-charge-requests/{$test->request->id}/confirm", ['confirmed_slot_at' => $slotAt]);
}

it('lists charge requests awaiting review for operations, without direct stall reservations', function () {
    EvReservation::factory()->withStall()->create(['station_id' => $this->station->id]);

    $this->withToken($this->opsToken)
        ->getJson('/api/v1/admin/ev-charge-requests')
        ->assertOk()
        ->assertJsonCount(1, 'charge_requests')
        ->assertJsonPath('charge_requests.0.id', $this->request->id)
        ->assertJsonPath('charge_requests.0.status.value', 'pending_review')
        ->assertJsonPath('summary.pending_review', 1)
        ->assertJsonPath('summary.confirmed', 0);
});

it('is for operations only', function () {
    $finance = User::factory()->create(['type' => UserType::Admin, 'admin_role' => AdminRole::Finance]);

    $this->withToken($finance->createToken('auth', ['admin', 'finance'])->plainTextToken)
        ->getJson('/api/v1/admin/ev-charge-requests')
        ->assertForbidden();
});

it('confirms a request for a slot and tells the driver', function () {
    $slot = now()->addHours(2);

    confirmChargeRequest($this, $slot->toIso8601String())
        ->assertOk()
        ->assertJsonPath('charge_request.status.value', 'confirmed')
        ->assertJsonPath('charge_request.confirmed_slot', $slot->copy()->setTimezone('Africa/Lagos')->format('h:i a'))
        ->assertJsonPath('charge_request.check_in_time', $slot->copy()->setTimezone('Africa/Lagos')->addMinutes(30)->format('h:i a'))
        ->assertJsonPath('charge_request.approver_name', 'E-tiGo Operations')
        ->assertJsonPath('charge_request.reviewed_by.id', $this->ops->id)
        ->assertJsonPath('charge_request.stall_id', null);

    expect(Notification::where('user_id', $this->driverUser->id)->value('type'))->toBe('ev_charge_request_confirmed');

    // The driver sees the slot, not who reviewed it.
    app('auth')->forgetGuards();
    $this->withToken($this->driverToken)
        ->getJson("/api/v1/driver/ev-reservations/{$this->request->id}")
        ->assertOk()
        ->assertJsonPath('reservation.status.value', 'confirmed')
        ->assertJsonMissingPath('reservation.reviewed_by');
});

it('refuses a slot in the past or more than 7 days ahead', function (string $offset) {
    confirmChargeRequest($this, now()->modify($offset)->toIso8601String())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmed_slot_at']);
})->with(['-1 hour', '+8 days']);

it('refuses to confirm a request that is no longer awaiting review', function () {
    $this->request->update(['status' => EvReservationStatus::Cancelled]);

    confirmChargeRequest($this, now()->addHour()->toIso8601String())
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only charge requests awaiting review can be confirmed.')
        ->assertJsonPath('current_status', 'cancelled');
});

it('refuses a station that is not taking reservations', function () {
    $this->station->update(['status' => EvStationStatus::Maintenance]);

    confirmChargeRequest($this, now()->addHour()->toIso8601String())
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This station is not taking reservations.');
});

it('holds a stall when the confirmed slot starts', function () {
    confirmChargeRequest($this, now()->addHour()->toIso8601String())->assertOk();

    $this->travel(61)->minutes();
    (new ProcessEvChargeRequestsJob)->handle(app(EvChargeRequestService::class));

    $reservation = $this->request->fresh();
    expect($reservation->status)->toBe(EvReservationStatus::Reserved)
        ->and($reservation->stall_id)->not->toBeNull()
        ->and($reservation->fee_charged_at)->not->toBeNull()
        ->and($reservation->stall->status)->toBe(EvStallStatus::Reserved)
        ->and(Notification::where('user_id', $this->driverUser->id)->pluck('type')->all())
        ->toContain('ev_reservation_ready');
});

it('holds a stall at once when the slot has already started', function () {
    confirmChargeRequest($this, now()->toIso8601String())
        ->assertOk()
        ->assertJsonPath('charge_request.status.value', 'reserved')
        ->assertJsonPath('charge_request.stall_id', fn ($id) => $id !== null);
});

it('queues the driver when every stall is busy at the slot', function () {
    $this->station->stalls()->update(['status' => EvStallStatus::Occupied]);

    confirmChargeRequest($this, now()->toIso8601String())
        ->assertOk()
        ->assertJsonPath('charge_request.status.value', 'queued')
        ->assertJsonPath('charge_request.queue_position', 1)
        ->assertJsonPath('charge_request.fee_charged_at', null);
});

it('declines a request with a reason and frees the driver to reserve a stall', function () {
    $this->withToken($this->opsToken)
        ->postJson("/api/v1/admin/ev-charge-requests/{$this->request->id}/decline", ['reason' => 'The station is closed for repairs today'])
        ->assertOk()
        ->assertJsonPath('charge_request.status.value', 'cancelled')
        ->assertJsonPath('charge_request.decline_reason', 'The station is closed for repairs today');

    expect(Notification::where('user_id', $this->driverUser->id)->value('body'))
        ->toBe("We couldn't book your charging request: The station is closed for repairs today. You can reserve a stall from the station list.");

    app('auth')->forgetGuards();
    $this->withToken($this->driverToken)
        ->postJson("/api/v1/driver/ev-reservations/stations/{$this->station->id}/reserve")
        ->assertCreated();
});

it('lets the driver cancel a confirmed slot before it starts', function () {
    confirmChargeRequest($this, now()->addHours(3)->toIso8601String())->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($this->driverToken)
        ->postJson("/api/v1/driver/ev-reservations/{$this->request->id}/cancel")
        ->assertOk()
        ->assertJsonPath('reservation.status.value', 'cancelled');
});

it('expires a request nobody reviewed before its day ended', function () {
    $this->travel(1)->days();
    (new ProcessEvChargeRequestsJob)->handle(app(EvChargeRequestService::class));

    expect($this->request->fresh()->status)->toBe(EvReservationStatus::Expired)
        ->and(Notification::where('user_id', $this->driverUser->id)->value('type'))->toBe('ev_charge_request_declined');
});

it('allows one open charge request at a time and no past dates', function () {
    $this->withToken($this->driverToken)
        ->postJson('/api/v1/driver/ev-reservations', ['battery_level' => 20])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You already have an active reservation.');

    $this->request->update(['status' => EvReservationStatus::Cancelled]);

    $this->withToken($this->driverToken)
        ->postJson('/api/v1/driver/ev-reservations', ['battery_level' => 20, 'requested_date' => now()->subDays(2)->toDateString()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['requested_date']);
});

it('holds the stall at the right time when the slot is sent with a Lagos offset', function () {
    confirmChargeRequest($this, now('Africa/Lagos')->addHour()->toIso8601String())->assertOk();

    expect($this->request->fresh()->confirmed_slot_at->equalTo(now()->addHour()))->toBeTrue();

    $this->travel(61)->minutes();
    (new ProcessEvChargeRequestsJob)->handle(app(EvChargeRequestService::class));

    expect($this->request->fresh()->status)->toBe(EvReservationStatus::Reserved);
});

it('tells the driver when a confirmed slot was missed', function () {
    confirmChargeRequest($this, now()->addHour()->toIso8601String())->assertOk();

    $this->travel(2)->hours();
    (new ProcessEvChargeRequestsJob)->handle(app(EvChargeRequestService::class));

    expect($this->request->fresh()->status)->toBe(EvReservationStatus::Expired)
        ->and(Notification::where('user_id', $this->driverUser->id)->where('type', 'ev_reservation_cancelled')->value('body'))
        ->toContain('check-in time passed');
});

it('keeps holding stalls for other drivers when one slot fails', function () {
    $other = EvReservation::factory()->chargeRequest()->feeWaived()->create([
        'driver_id' => User::factory()->create(['type' => UserType::Driver])->id,
        'station_id' => $this->station->id,
        'status' => EvReservationStatus::Confirmed,
        'confirmed_slot_at' => now()->subMinute(),
    ]);
    $this->request->update(['status' => EvReservationStatus::Confirmed, 'confirmed_slot_at' => now()->subMinutes(2)]);

    $calls = 0;
    $this->partialMock(EvReservationFeeService::class, fn ($mock) => $mock->shouldReceive('charge')
        ->andReturnUsing(function () use (&$calls) {
            if ($calls++ === 0) {
                throw new LogicException('Ledger unavailable');
            }
        }));

    expect(app(EvChargeRequestService::class)->processDue()['started'])->toBe(1)
        ->and($this->request->fresh()->status)->toBe(EvReservationStatus::Confirmed)
        ->and($other->fresh()->status)->toBe(EvReservationStatus::Reserved);
});

it('dates a charge request by the Lagos day just after midnight', function () {
    $this->travelTo(now('Africa/Lagos')->addDay()->setTime(0, 30));
    $this->request->update(['status' => EvReservationStatus::Cancelled]);
    $lagosToday = now('Africa/Lagos')->toDateString();

    $this->withToken($this->driverToken)
        ->postJson('/api/v1/driver/ev-reservations', ['battery_level' => 20, 'requested_date' => now('Africa/Lagos')->subDay()->toDateString()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['requested_date']);

    $this->withToken($this->driverToken)
        ->postJson('/api/v1/driver/ev-reservations', ['battery_level' => 20])
        ->assertCreated()
        ->assertJsonPath('reservation.requested_date', $lagosToday);

    expect(app(EvChargeRequestService::class)->processDue()['expired'])->toBe(0);
});

it('keeps the expected charging time from the request and uses it at the stall', function () {
    $this->request->delete();

    $id = $this->withToken($this->driverToken)
        ->postJson('/api/v1/driver/ev-reservations', ['battery_level' => 20, 'estimated_charge_minutes' => 45])
        ->assertCreated()
        ->assertJsonPath('reservation.kind', 'request')
        ->assertJsonPath('reservation.estimated_charge_minutes', 45)
        ->json('reservation.id');
    $this->request = EvReservation::findOrFail($id);
    app('auth')->forgetGuards();

    confirmChargeRequest($this, now()->toIso8601String())
        ->assertOk()
        ->assertJsonPath('reservation.status.value', 'reserved')
        ->assertJsonPath('charge_request.status.value', 'reserved')
        ->assertJsonPath('reservation.fee_amount_kobo', 50000)
        ->assertJsonPath('reservation.check_in_deadline', now()->addMinutes(30)->toISOString());
    app('auth')->forgetGuards();

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/driver/ev-reservations/{$id}/activate")
        ->assertOk();

    expect($this->request->fresh()->stall->estimated_departure_at->toISOString())->toBe(now()->addMinutes(45)->toISOString());
});

it('lets the driver give the charging time when starting at the stall', function () {
    $reservation = EvReservation::factory()->create([
        'driver_id' => $this->driverUser->id,
        'station_id' => $this->station->id,
        'stall_id' => $this->station->stalls()->first()->id,
    ]);
    $this->request->delete();

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/driver/ev-reservations/{$reservation->id}/activate", ['estimated_charge_minutes' => 90])
        ->assertOk()
        ->assertJsonPath('reservation.estimated_charge_minutes', 90);

    expect($reservation->fresh()->stall->estimated_departure_at->toISOString())->toBe(now()->addMinutes(90)->toISOString());
});

it('tells a queued driver how many are ahead of them', function (int $position, int $ahead, string $description) {
    $this->request->update(['status' => EvReservationStatus::Queued, 'queue_position' => $position]);

    $this->withToken($this->driverToken)
        ->getJson("/api/v1/driver/ev-reservations/{$this->request->id}")
        ->assertJsonPath('reservation.queue_ahead_count', $ahead)
        ->assertJsonPath('reservation.queue_ahead_description', $description);
})->with([
    [1, 0, "You're next in line"],
    [2, 1, '1 driver ahead of you'],
    [4, 3, '3 drivers ahead of you'],
]);
