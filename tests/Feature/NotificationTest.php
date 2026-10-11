<?php

use App\Models\Notification;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('auth')->plainTextToken;
});

it('lists notifications for the authenticated user', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'ride_completed',
        'title' => 'Ride Complete',
        'body' => 'Your ride has been completed.',
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/notifications');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Ride Complete')
        ->assertJsonPath('data.0.type', 'ride_completed')
        ->assertJsonPath('data.0.type_label', 'Ride Completed')
        ->assertJsonPath('data.0.category', 'ride_updates');
});

it('does not list notifications belonging to another user', function () {
    $otherUser = User::factory()->create();

    Notification::create([
        'user_id' => $otherUser->id,
        'type' => 'ride_completed',
        'title' => 'Not Mine',
        'body' => 'This belongs to someone else.',
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/notifications');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns unread count', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'promo_expiring',
        'title' => 'Promo',
        'body' => 'You have a promo.',
        'is_read' => false,
    ]);

    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'promo_expiring',
        'title' => 'Old Promo',
        'body' => 'Already read.',
        'is_read' => true,
        'read_at' => now(),
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/notifications/unread-count');

    $response->assertOk()
        ->assertJson(['unread_count' => 1]);
});

it('marks a notification as read', function () {
    $notification = Notification::create([
        'user_id' => $this->user->id,
        'type' => 'ride_completed',
        'title' => 'Ride Complete',
        'body' => 'Done.',
        'is_read' => false,
    ]);

    $response = $this->withToken($this->token)
        ->patchJson("/api/v1/notifications/{$notification->id}/read");

    $response->assertOk()
        ->assertJsonPath('data.is_read', true);

    $notification->refresh();
    expect($notification->is_read)->toBeTrue();
    expect($notification->read_at)->not->toBeNull();
});

it('returns 403 when marking another user notification as read', function () {
    $otherUser = User::factory()->create();

    $notification = Notification::create([
        'user_id' => $otherUser->id,
        'type' => 'ride_completed',
        'title' => 'Not Mine',
        'body' => 'Belongs to another user.',
    ]);

    $response = $this->withToken($this->token)
        ->patchJson("/api/v1/notifications/{$notification->id}/read");

    $response->assertForbidden();
});

it('marks all notifications as read', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'promo_expiring',
        'title' => 'One',
        'body' => 'First.',
    ]);

    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'promo_expiring',
        'title' => 'Two',
        'body' => 'Second.',
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/notifications/read-all');

    $response->assertOk()
        ->assertJsonPath('updated_count', 2);

    expect(Notification::where('user_id', $this->user->id)->where('is_read', false)->count())->toBe(0);
});

it('returns 401 when unauthenticated', function () {
    $response = $this->getJson('/api/v1/notifications');

    $response->assertUnauthorized();
});

it('filters notifications by type', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'ride_completed',
        'title' => 'Ride Done',
        'body' => 'Trip completed.',
    ]);

    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'promo_expiring',
        'title' => 'Promo',
        'body' => 'Expiring soon.',
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/notifications?type=ride_completed');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'ride_completed');
});

it('filters notifications by category', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'ride_completed',
        'title' => 'Ride Done',
        'body' => 'Trip completed.',
    ]);

    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'topup_success',
        'title' => 'Top-up',
        'body' => 'Wallet topped up.',
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/notifications?category=payments');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'topup_success');
});

it('filters notifications by read status', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'ride_completed',
        'title' => 'Unread',
        'body' => 'Not read yet.',
        'is_read' => false,
    ]);

    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'ride_completed',
        'title' => 'Read',
        'body' => 'Already read.',
        'is_read' => true,
        'read_at' => now(),
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/notifications?is_read=false');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Unread');
});

it('deletes a notification', function () {
    $notification = Notification::create([
        'user_id' => $this->user->id,
        'type' => 'ride_completed',
        'title' => 'To Delete',
        'body' => 'Bye.',
    ]);

    $response = $this->withToken($this->token)
        ->deleteJson("/api/v1/notifications/{$notification->id}");

    $response->assertOk()
        ->assertJsonPath('message', 'Notification deleted.');

    $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
});

it('returns 403 when deleting another user notification', function () {
    $otherUser = User::factory()->create();

    $notification = Notification::create([
        'user_id' => $otherUser->id,
        'type' => 'ride_completed',
        'title' => 'Not Mine',
        'body' => 'Cannot delete.',
    ]);

    $response = $this->withToken($this->token)
        ->deleteJson("/api/v1/notifications/{$notification->id}");

    $response->assertForbidden();
});
