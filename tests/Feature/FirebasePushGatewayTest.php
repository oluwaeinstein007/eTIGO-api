<?php

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\FirebasePushGateway;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;

function registerDevice(User $user, string $token): void
{
    DeviceToken::create(['user_id' => $user->id, 'platform' => 'android', 'token' => $token, 'is_active' => true]);
}

it('sends booleans in push data as "true" and "false", which apps can parse', function () {
    $user = User::factory()->create();
    registerDevice($user, 'token-a');

    $sentData = null;
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')
        ->once()
        ->withArgs(function (CloudMessage $message) use (&$sentData) {
            $sentData = $message->jsonSerialize()['data'];

            return true;
        })
        ->andReturn(MulticastSendReport::withItems([
            SendReport::success(MessageTarget::with(MessageTarget::TOKEN, 'token-a'), []),
        ]));

    (new FirebasePushGateway($messaging))->sendToUser($user->id, [
        'title' => 'New Ride Request',
        'body' => 'Pickup at Yaba',
        'data' => ['is_third_party' => false, 'reinstated' => true, 'fare_estimate' => 3650.5, 'response_timeout' => 20],
    ]);

    expect($sentData)->toBe([
        'is_third_party' => 'false',
        'reinstated' => 'true',
        'fare_estimate' => '3650.5',
        'response_timeout' => '20',
    ]);
});

it('logs a push that a device did not get, without the token', function () {
    Log::spy();
    $user = User::factory()->create();
    registerDevice($user, 'token-live');
    registerDevice($user, 'token-gone');

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->once()->andReturn(MulticastSendReport::withItems([
        SendReport::success(MessageTarget::with(MessageTarget::TOKEN, 'token-live'), []),
        SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, 'token-gone'), NotFound::becauseTokenNotFound('token-gone')),
    ]));

    (new FirebasePushGateway($messaging))->sendToUser($user->id, [
        'title' => 'New Ride Request',
        'body' => 'Pickup at Yaba',
        'data' => ['type' => 'ride_request'],
    ]);

    Log::shouldHaveReceived('warning')->once()->with('Push not delivered to every device', [
        'user_id' => $user->id,
        'type' => 'ride_request',
        'success_count' => 1,
        'failure_count' => 1,
        'errors' => ['NotFound'],
    ]);
    expect(DeviceToken::where('token', 'token-gone')->value('is_active'))->toBeFalse();
});

it('logs a push skipped because the user has no active device', function () {
    Log::spy();
    $user = User::factory()->create();

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('sendMulticast');

    (new FirebasePushGateway($messaging))->sendToUser($user->id, [
        'title' => 'New Ride Request',
        'body' => 'Pickup at Yaba',
        'data' => ['type' => 'ride_request'],
    ]);

    Log::shouldHaveReceived('info')->once()->with('Push skipped: user has no active device', [
        'user_id' => $user->id,
        'type' => 'ride_request',
    ]);
});
