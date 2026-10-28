<?php

use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Enums\UserType;
use App\Models\Driver;
use App\Models\DriverRepair;
use App\Models\Notification;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('filesystems.uploads'));
    $this->travelTo(now('Africa/Lagos')->setTime(9, 0));

    $this->driverUser = User::factory()->create(['type' => UserType::Driver, 'first_name' => 'Musa', 'last_name' => 'Ibrahim']);
    $this->driver = Driver::factory()->create(['user_id' => $this->driverUser->id, 'status' => DriverStatus::Approved]);
    $this->vehicle = Vehicle::factory()->create(['driver_id' => $this->driver->id, 'plate_number' => 'KJA-210-XY']);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->ops = User::factory()->create(['type' => UserType::Admin, 'admin_role' => AdminRole::Operations, 'first_name' => 'Ada', 'last_name' => 'Okafor']);
    $this->opsToken = $this->ops->createToken('auth', ['admin', 'operations'])->plainTextToken;

    $this->repair = DriverRepair::findOrFail(repairAs($this, $this->driverToken)
        ->postJson('/api/v1/driver/repairs', [
            'type' => 'repair',
            'title' => 'Brake noise',
            'details' => 'Squeal when braking at low speed.',
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time' => '10:00 am',
        ])
        ->assertCreated()
        ->json('repair.id'));
});

/** Each request authenticates afresh, so a test can switch between driver and operations. */
function repairAs($test, string $token)
{
    app('auth')->forgetGuards();

    return $test->withToken($token);
}

function scheduleRepair($test, array $overrides = [])
{
    return repairAs($test, $test->opsToken)->postJson("/api/v1/admin/repair-requests/{$test->repair->id}/schedule", [
        'scheduled_at' => now('Africa/Lagos')->addDay()->setTime(10, 0)->toIso8601String(),
        'workshop_name' => 'E-tiGo Fleet Workshop',
        'workshop_address' => '4 Akin Adesola St, Victoria Island',
        'technician_name' => 'Emeka',
        ...$overrides,
    ]);
}

it('lists open requests for operations with the driver and counts', function () {
    $done = DriverRepair::factory()->create(['status' => 'completed']);

    repairAs($this, $this->opsToken)
        ->getJson('/api/v1/admin/repair-requests')
        ->assertOk()
        ->assertJsonCount(1, 'repairs')
        ->assertJsonPath('repairs.0.id', $this->repair->id)
        ->assertJsonPath('repairs.0.driver.name', 'Musa Ibrahim')
        ->assertJsonPath('repairs.0.vehicle_plate', 'KJA-210-XY')
        ->assertJsonPath('summary.in_progress', 1)
        ->assertJsonPath('summary.completed', 1);

    repairAs($this, $this->opsToken)
        ->getJson('/api/v1/admin/repair-requests?status=completed')
        ->assertJsonPath('repairs.0.id', $done->id);

    repairAs($this, $this->opsToken)
        ->getJson('/api/v1/admin/repair-requests?search=KJA-210')
        ->assertJsonCount(1, 'repairs');

    repairAs($this, $this->opsToken)
        ->getJson('/api/v1/admin/repair-requests?status=fixed')
        ->assertUnprocessable();
});

it('is for operations only', function () {
    $support = User::factory()->create(['type' => UserType::Admin, 'admin_role' => AdminRole::Support]);

    repairAs($this, $support->createToken('auth', ['admin', 'support'])->plainTextToken)
        ->getJson('/api/v1/admin/repair-requests')
        ->assertForbidden();
});

it('books a workshop visit and tells the driver', function () {
    scheduleRepair($this)
        ->assertOk()
        ->assertJsonPath('repair.status.value', 'scheduled')
        ->assertJsonPath('repair.appointment.workshop_name', 'E-tiGo Fleet Workshop')
        ->assertJsonPath('repair.appointment.time', '10:00 am')
        ->assertJsonPath('repair.progress_steps.1.state', 'completed')
        ->assertJsonPath('repair.progress_steps.1.subtitle', 'Emeka')
        ->assertJsonPath('repair.progress_steps.2.state', 'in_progress')
        ->assertJsonPath('repair.handled_by.name', 'Ada Okafor');

    $notification = Notification::where('user_id', $this->driverUser->id)->latest('created_at')->first();
    expect($notification->type)->toBe('repair_request_scheduled')
        ->and($notification->body)->toContain('E-tiGo Fleet Workshop');

    // Rebooking is allowed while the visit has not happened.
    scheduleRepair($this, ['scheduled_at' => now('Africa/Lagos')->addDays(2)->setTime(14, 0)->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('repair.appointment.time', '02:00 pm');
});

it('refuses a visit in the past', function () {
    scheduleRepair($this, ['scheduled_at' => now()->subHour()->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_at']);
});

it('completes a request with a report the driver cannot delete', function () {
    scheduleRepair($this)->assertOk();

    $response = repairAs($this, $this->opsToken)
        ->post("/api/v1/admin/repair-requests/{$this->repair->id}/complete", [
            'summary' => 'Replaced front brake pads. Discs within limits.',
            'report' => UploadedFile::fake()->create('report.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('repair.status.value', 'completed')
        ->assertJsonPath('repair.completion_summary', 'Replaced front brake pads. Discs within limits.')
        ->assertJsonPath('repair.progress_steps.3.state', 'completed')
        ->assertJsonPath('repair.can_cancel', false);

    $report = collect($response->json('repair.attachments'))->firstWhere('uploaded_by', 'operations');
    expect($report)->not->toBeNull()
        ->and(Notification::where('user_id', $this->driverUser->id)->latest('created_at')->value('type'))->toBe('repair_request_completed');

    repairAs($this, $this->driverToken)
        ->deleteJson("/api/v1/driver/repairs/{$this->repair->id}/attachments/{$report['id']}")
        ->assertForbidden();
});

it('cancels with a reason the driver sees', function () {
    repairAs($this, $this->opsToken)
        ->postJson("/api/v1/admin/repair-requests/{$this->repair->id}/cancel", ['reason' => 'Covered by the dealer warranty'])
        ->assertOk()
        ->assertJsonPath('repair.status.value', 'cancelled')
        ->assertJsonPath('repair.cancelled_by', 'operations')
        ->assertJsonPath('repair.cancellation_reason', 'Covered by the dealer warranty');

    expect(Notification::where('user_id', $this->driverUser->id)->latest('created_at')->value('body'))
        ->toContain('Covered by the dealer warranty');

    // Finished requests stay finished.
    scheduleRepair($this)
        ->assertUnprocessable()
        ->assertJsonPath('current_status', 'cancelled');
});

it('lets the driver cancel their own open request', function () {
    repairAs($this, $this->driverToken)
        ->postJson("/api/v1/driver/repairs/{$this->repair->id}/cancel", ['reason' => 'Fixed at a roadside mechanic'])
        ->assertOk()
        ->assertJsonPath('repair.status.value', 'cancelled')
        ->assertJsonPath('repair.cancelled_by', 'driver');

    $other = User::factory()->create(['type' => UserType::Driver]);
    Driver::factory()->create(['user_id' => $other->id]);

    repairAs($this, $other->createToken('auth', ['driver'])->plainTextToken)
        ->postJson("/api/v1/driver/repairs/{$this->repair->id}/cancel")
        ->assertForbidden();

    repairAs($this, $this->driverToken)
        ->postJson("/api/v1/driver/repairs/{$this->repair->id}/cancel")
        ->assertUnprocessable();
});

it('lets operations add a file at any stage', function () {
    repairAs($this, $this->opsToken)
        ->post("/api/v1/admin/repair-requests/{$this->repair->id}/attachments", [
            'attachment' => UploadedFile::fake()->image('quote.jpg'),
            'title' => 'Workshop quote',
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('attachment.title', 'Workshop quote')
        ->assertJsonPath('attachment.uploaded_by', 'operations');
});
