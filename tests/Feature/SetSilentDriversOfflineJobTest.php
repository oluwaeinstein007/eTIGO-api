<?php

use App\Enums\RideStatus;
use App\Jobs\SetSilentDriversOfflineJob;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\DriverSetOfflineNotification;
use App\Services\DriverLocationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config(['matching.silent_driver_offline_minutes' => 10]);

    $this->locationService = Mockery::mock(DriverLocationService::class);
    $this->locationService->shouldReceive('removeDriver')->byDefault();
    $this->app->instance(DriverLocationService::class, $this->locationService);
});

function driverOnlineFor(int $minutes): Driver
{
    $driver = Driver::factory()->approved()->for(User::factory()->driver())->create(['is_online' => true]);
    Driver::whereKey($driver->id)->update(['updated_at' => now()->subMinutes($minutes)]);

    return $driver->refresh();
}

function runSilentDriversJob(): void
{
    app()->call([new SetSilentDriversOfflineJob, 'handle']);
}

it('sets offline a driver whose app has sent no location for the threshold', function (?int $lastSeenMinutesAgo) {
    $driver = driverOnlineFor(minutes: 60);
    $this->locationService->shouldReceive('lastSeenAt')->with($driver->id)
        ->andReturn($lastSeenMinutesAgo === null ? null : now()->subMinutes($lastSeenMinutesAgo));
    $this->locationService->shouldReceive('removeDriver')->once()->with($driver->id, $driver->user_id);

    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeFalse()
        ->and(AuditLog::where('auditable_id', $driver->id)->where('event', 'driver_went_offline')->first()->new_values['reason'])
        ->toBe('no_location');
    Notification::assertSentTo($driver->user, DriverSetOfflineNotification::class);
})->with([
    'never sent one' => [null],
    'last sent 25 minutes ago' => [25],
]);

it('keeps online a driver whose app is sending locations', function () {
    $driver = driverOnlineFor(minutes: 60);
    $this->locationService->shouldReceive('lastSeenAt')->with($driver->id)->andReturn(now()->subMinutes(2));

    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeTrue();
    Notification::assertNothingSent();
});

it('gives a driver who just went online time to send a first location', function () {
    $driver = driverOnlineFor(minutes: 3);
    $this->locationService->shouldNotReceive('lastSeenAt');

    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeTrue();
});

it('leaves a silent driver on a trip online', function () {
    $driver = driverOnlineFor(minutes: 60);
    Ride::factory()->create(['driver_id' => $driver->user_id, 'status' => RideStatus::InProgress]);
    $this->locationService->shouldNotReceive('lastSeenAt');

    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeTrue();
    Notification::assertNothingSent();
});

it('ignores drivers who are already offline', function () {
    $driver = Driver::factory()->approved()->for(User::factory()->driver())->create(['is_online' => false]);
    $this->locationService->shouldNotReceive('lastSeenAt');

    runSilentDriversJob();

    Notification::assertNothingSent();
});
