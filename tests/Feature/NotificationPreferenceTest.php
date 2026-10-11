<?php

use App\Enums\NotificationCategory;
use App\Models\NotificationPreference;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('auth')->plainTextToken;
});

it('returns all notification categories with defaults', function () {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/notification-preferences');

    $response->assertOk()
        ->assertJsonCount(count(NotificationCategory::cases()), 'data')
        ->assertJsonPath('data.0.category', 'ride_updates')
        ->assertJsonPath('data.0.push_enabled', true)
        ->assertJsonPath('data.0.in_app_enabled', true)
        ->assertJsonPath('data.0.is_critical', false);
});

it('returns saved preferences over defaults', function () {
    NotificationPreference::create([
        'user_id' => $this->user->id,
        'category' => NotificationCategory::Promotions->value,
        'push_enabled' => false,
        'in_app_enabled' => true,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/notification-preferences');

    $response->assertOk();

    $promotions = collect($response->json('data'))
        ->firstWhere('category', 'promotions');

    expect($promotions['push_enabled'])->toBeFalse();
    expect($promotions['in_app_enabled'])->toBeTrue();
});

it('updates notification preferences', function () {
    $response = $this->withToken($this->token)
        ->putJson('/api/v1/notification-preferences', [
            'preferences' => [
                [
                    'category' => 'promotions',
                    'push_enabled' => false,
                    'in_app_enabled' => true,
                ],
                [
                    'category' => 'gamification',
                    'push_enabled' => true,
                    'in_app_enabled' => false,
                ],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.0.category', 'promotions')
        ->assertJsonPath('data.0.push_enabled', false)
        ->assertJsonPath('data.1.category', 'gamification')
        ->assertJsonPath('data.1.in_app_enabled', false);

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $this->user->id,
        'category' => 'promotions',
        'push_enabled' => false,
    ]);
});

it('prevents disabling critical notification categories', function () {
    $response = $this->withToken($this->token)
        ->putJson('/api/v1/notification-preferences', [
            'preferences' => [
                [
                    'category' => 'safety',
                    'push_enabled' => false,
                    'in_app_enabled' => false,
                ],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.0.push_enabled', true)
        ->assertJsonPath('data.0.in_app_enabled', true);
});

it('rejects invalid category', function () {
    $response = $this->withToken($this->token)
        ->putJson('/api/v1/notification-preferences', [
            'preferences' => [
                [
                    'category' => 'invalid_category',
                    'push_enabled' => false,
                    'in_app_enabled' => true,
                ],
            ],
        ]);

    $response->assertUnprocessable();
});

it('requires authentication', function () {
    $response = $this->getJson('/api/v1/notification-preferences');
    $response->assertUnauthorized();

    $response = $this->putJson('/api/v1/notification-preferences', [
        'preferences' => [['category' => 'promotions', 'push_enabled' => false, 'in_app_enabled' => true]],
    ]);
    $response->assertUnauthorized();
});

it('marks safety category as critical', function () {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/notification-preferences');

    $safety = collect($response->json('data'))
        ->firstWhere('category', 'safety');

    expect($safety['is_critical'])->toBeTrue();
});
