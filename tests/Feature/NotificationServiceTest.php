<?php

use App\Contracts\PushNotificationGateway;
use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\NotificationService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->pushGateway = Mockery::mock(PushNotificationGateway::class);
    $this->service = new NotificationService($this->pushGateway);
});

it('persists and sends push notification', function () {
    $this->pushGateway->shouldReceive('sendToUser')
        ->once()
        ->with($this->user->id, Mockery::type('array'));

    $notification = $this->service->send(
        userId: $this->user->id,
        type: NotificationType::RideCompleted,
        title: 'Ride Completed',
        body: 'Your ride has been completed.',
        data: ['ride_id' => 'abc-123'],
    );

    expect($notification)->not->toBeNull();
    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'type' => 'ride_completed',
        'title' => 'Ride Completed',
    ]);
});

it('respects user preference to disable push', function () {
    NotificationPreference::create([
        'user_id' => $this->user->id,
        'category' => NotificationCategory::Payments->value,
        'push_enabled' => false,
        'in_app_enabled' => true,
    ]);

    $this->pushGateway->shouldNotReceive('sendToUser');

    $notification = $this->service->send(
        userId: $this->user->id,
        type: NotificationType::RideWalletPayment,
        title: 'Payment Received',
        body: 'Your wallet was charged.',
    );

    expect($notification)->not->toBeNull();
    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'type' => 'ride_wallet_payment',
    ]);
});

it('respects user preference to disable in-app', function () {
    NotificationPreference::create([
        'user_id' => $this->user->id,
        'category' => NotificationCategory::Promotions->value,
        'push_enabled' => true,
        'in_app_enabled' => false,
    ]);

    $this->pushGateway->shouldReceive('sendToUser')->once();

    $notification = $this->service->send(
        userId: $this->user->id,
        type: NotificationType::PromoExpiring,
        title: 'Promo Expiring',
        body: 'Your promo code expires soon.',
    );

    expect($notification)->toBeNull();
    $this->assertDatabaseMissing('notifications', [
        'user_id' => $this->user->id,
        'type' => 'promo_expiring',
    ]);
});

it('always sends critical notifications regardless of preferences', function () {
    NotificationPreference::create([
        'user_id' => $this->user->id,
        'category' => NotificationCategory::Safety->value,
        'push_enabled' => false,
        'in_app_enabled' => false,
    ]);

    $this->pushGateway->shouldReceive('sendToUser')->once();

    $notification = $this->service->sendCritical(
        userId: $this->user->id,
        type: NotificationType::SosCheckIn,
        title: 'SOS Check-In',
        body: 'Are you safe?',
    );

    expect($notification)->not->toBeNull();
    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'type' => 'sos_check_in',
    ]);
});

it('handles push gateway failure gracefully', function () {
    $this->pushGateway->shouldReceive('sendToUser')
        ->once()
        ->andThrow(new RuntimeException('FCM unavailable'));

    $notification = $this->service->send(
        userId: $this->user->id,
        type: NotificationType::RideCompleted,
        title: 'Ride Done',
        body: 'Completed.',
    );

    expect($notification)->not->toBeNull();
    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'type' => 'ride_completed',
    ]);
});

it('sends push-only notification without persisting', function () {
    $this->pushGateway->shouldReceive('sendToUser')->once();

    $this->service->sendPushOnly(
        userId: $this->user->id,
        title: 'ETA Updated',
        body: 'Driver arrives in 3 min',
        data: ['eta_minutes' => 3],
    );

    $this->assertDatabaseMissing('notifications', [
        'user_id' => $this->user->id,
        'title' => 'ETA Updated',
    ]);
});

it('sends to multiple users', function () {
    $user2 = User::factory()->create();

    $this->pushGateway->shouldReceive('sendToUser')->twice();

    $this->service->sendToMany(
        userIds: [$this->user->id, $user2->id],
        type: NotificationType::RideCompleted,
        title: 'Ride Done',
        body: 'Trip completed.',
    );

    expect(Notification::where('type', 'ride_completed')->count())->toBe(2);
});
