<?php

use App\Enums\RideStatus;
use App\Jobs\CheckSilentDriversJob;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\DriverLocationMissingNotification;
use App\Notifications\DriverSetOfflineNotification;
use App\Services\DriverLocationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config([
        'matching.silent_driver_warning_minutes' => 5,
        'matching.silent_driver_offline_minutes' => 30,
    ]);

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

function lastSeen(Driver $driver, ?int $minutesAgo): void
{
    test()->locationService->shouldReceive('lastSeenAt')->with($driver->id)
        ->andReturn($minutesAgo === null ? null : now()->subMinutes($minutesAgo));
}

function runSilentDriversJob(): void
{
    app()->call([new CheckSilentDriversJob, 'handle']);
}

it('warns a driver once when their app stops sending a location, without setting them offline', function () {
    $driver = driverOnlineFor(minutes: 60);
    lastSeen($driver, minutesAgo: 6);

    runSilentDriversJob();
    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeTrue();
    Notification::assertSentToTimes($driver->user, DriverLocationMissingNotification::class, 1);
    Notification::assertSentTo($driver->user, DriverLocationMissingNotification::class, fn ($n) => $n->onTrip === false);
    Notification::assertNotSentTo($driver->user, DriverSetOfflineNotification::class);
});

it('warns again when the app goes quiet again after sending locations', function () {
    $driver = driverOnlineFor(minutes: 60);
    $this->locationService->shouldReceive('lastSeenAt')->with($driver->id)
        ->andReturn(now()->subMinutes(20), now()->subMinutes(6));

    runSilentDriversJob();
    runSilentDriversJob();

    Notification::assertSentToTimes($driver->user, DriverLocationMissingNotification::class, 2);
});

it('sets offline a driver whose app has sent no location for the offline time', function (?int $lastSeenMinutesAgo) {
    $driver = driverOnlineFor(minutes: 60);
    lastSeen($driver, $lastSeenMinutesAgo);
    $this->locationService->shouldReceive('removeDriver')->once()->with($driver->id, $driver->user_id);

    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeFalse()
        ->and(AuditLog::where('auditable_id', $driver->id)->where('event', 'driver_went_offline')->first()->new_values['reason'])
        ->toBe('no_location');
    Notification::assertSentTo($driver->user, DriverSetOfflineNotification::class);
})->with([
    'never sent one' => [null],
    'last sent 45 minutes ago' => [45],
]);

it('keeps online, without a warning, a driver whose app is sending locations', function () {
    $driver = driverOnlineFor(minutes: 60);
    lastSeen($driver, minutesAgo: 1);

    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeTrue();
    Notification::assertNothingSent();
});

it('gives a driver who just went online time to send a first location', function () {
    $driver = driverOnlineFor(minutes: 3);
    $this->locationService->shouldNotReceive('lastSeenAt');

    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeTrue();
    Notification::assertNothingSent();
});

it('counts the silence from going online when the last location came before it', function () {
    $driver = driverOnlineFor(minutes: 10);
    lastSeen($driver, minutesAgo: 120);

    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeTrue();
    Notification::assertSentTo($driver->user, DriverLocationMissingNotification::class);
});

it('only warns a silent driver on a trip, however long the silence', function () {
    $driver = driverOnlineFor(minutes: 120);
    lastSeen($driver, minutesAgo: 90);
    Ride::factory()->create(['driver_id' => $driver->user_id, 'status' => RideStatus::InProgress]);

    runSilentDriversJob();

    expect($driver->refresh()->is_online)->toBeTrue();
    Notification::assertSentTo($driver->user, DriverLocationMissingNotification::class, fn ($n) => $n->onTrip === true);
    Notification::assertNotSentTo($driver->user, DriverSetOfflineNotification::class);
});

it('ignores drivers who are already offline', function () {
    Driver::factory()->approved()->for(User::factory()->driver())->create(['is_online' => false]);
    $this->locationService->shouldNotReceive('lastSeenAt');

    runSilentDriversJob();

    Notification::assertNothingSent();
});
