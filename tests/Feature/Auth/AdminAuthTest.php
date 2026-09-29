<?php

use App\Models\User;

it('allows admin to login with valid credentials', function () {
    User::factory()->admin()->create([
        'email' => 'admin@etigo.com',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'admin@etigo.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'user', 'token'])
        ->assertJson([
            'user' => [
                'email' => 'admin@etigo.com',
                'type' => 'admin',
            ],
        ]);
});

it('rejects admin login with invalid credentials', function () {
    User::factory()->admin()->create([
        'email' => 'admin@etigo.com',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'admin@etigo.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Invalid credentials.']);
});

it('rejects non-admin user attempting admin login', function () {
    User::factory()->passenger()->create([
        'email' => 'passenger@test.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'passenger@test.com',
        'password' => 'password',
    ]);

    $response->assertUnauthorized();
});

it('rejects deactivated admin login', function () {
    User::factory()->admin()->inactive()->create([
        'email' => 'deactivated@etigo.com',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'deactivated@etigo.com',
        'password' => 'password',
    ]);

    $response->assertForbidden();
});

it('returns current admin user via me endpoint', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/auth/me');

    $response->assertOk()
        ->assertJsonStructure(['user' => ['id', 'first_name', 'last_name', 'email', 'type']]);
});

it('allows admin to logout', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/auth/logout');

    $response->assertOk()
        ->assertJson(['message' => 'Logged out successfully.']);
});
