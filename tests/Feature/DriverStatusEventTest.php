<?php

use App\Enums\DriverStatus;
use App\Enums\KycStatus;
use App\Events\DriverStatusUpdated;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Event;

it('dispatches DriverStatusUpdated when driver status changes', function () {
    Event::fake([DriverStatusUpdated::class]);

    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
        'status' => DriverStatus::PendingReview,
    ]);

    $driver->update([
        'status' => DriverStatus::Approved,
        'approved_at' => now(),
    ]);

    Event::assertDispatched(DriverStatusUpdated::class, function (DriverStatusUpdated $event) use ($driver, $user) {
        return $event->driverId === $driver->id
            && $event->driverUserId === $user->id
            && $event->status === 'approved'
            && $event->previousStatus === 'pending_review'
            && $event->broadcastAs() === 'driver.status.updated'
            && count($event->broadcastOn()) === 1
            && $event->broadcastOn()[0]->name === "private-driver.{$user->id}";
    });
});

it('dispatches DriverStatusUpdated with rejection reason when driver is rejected', function () {
    Event::fake([DriverStatusUpdated::class]);

    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
        'status' => DriverStatus::PendingReview,
    ]);

    $driver->update([
        'status' => DriverStatus::Rejected,
        'rejection_reason' => 'Blurry driver license',
    ]);

    Event::assertDispatched(DriverStatusUpdated::class, function (DriverStatusUpdated $event) use ($driver, $user) {
        $payload = $event->broadcastWith();

        return $event->driverId === $driver->id
            && $event->status === 'rejected'
            && $event->rejectionReason === 'Blurry driver license'
            && $payload['status'] === 'rejected'
            && $payload['rejection_reason'] === 'Blurry driver license'
            && $payload['driver_user_id'] === $user->id;
    });
});

it('dispatches DriverStatusUpdated when kyc status changes', function () {
    Event::fake([DriverStatusUpdated::class]);

    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
        'status' => DriverStatus::PendingReview,
        'kyc_status' => KycStatus::InProgress,
    ]);

    $driver->update([
        'kyc_status' => KycStatus::Verified,
    ]);

    Event::assertDispatched(DriverStatusUpdated::class, function (DriverStatusUpdated $event) use ($driver) {
        return $event->driverId === $driver->id
            && $event->kycStatus === 'verified';
    });
});

it('does not dispatch DriverStatusUpdated when status and kyc status are unchanged', function () {
    Event::fake([DriverStatusUpdated::class]);

    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
        'status' => DriverStatus::Approved,
        'is_online' => false,
    ]);

    $driver->update([
        'is_online' => true,
    ]);

    Event::assertNotDispatched(DriverStatusUpdated::class);
});
