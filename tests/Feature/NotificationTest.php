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
        ->assertJsonPath('data.0.title', 'Ride Complete');
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
        'type' => 'promo',
        'title' => 'Promo',
        'body' => 'You have a promo.',
        'is_read' => false,
    ]);

    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'promo',
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

    $response->assertOk();

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
        'type' => 'promo',
        'title' => 'One',
        'body' => 'First.',
    ]);

    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'promo',
        'title' => 'Two',
        'body' => 'Second.',
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/notifications/read-all');

    $response->assertOk();

    expect(Notification::where('user_id', $this->user->id)->where('is_read', false)->count())->toBe(0);
});

it('returns 401 when unauthenticated', function () {
    $response = $this->getJson('/api/v1/notifications');

    $response->assertUnauthorized();
});
