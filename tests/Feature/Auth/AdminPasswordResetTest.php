<?php

use App\Enums\AdminRole;
use App\Models\User;
use App\Notifications\AdminPasswordResetNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    Notification::fake();
});

it('sends a password reset link for a valid admin email', function () {
    $admin = User::factory()->admin(AdminRole::Operations)->create([
        'email' => 'ops@etigo.com',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/forgot-password', [
        'email' => 'ops@etigo.com',
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'If the email exists in our system, a password reset link has been sent.']);

    Notification::assertSentTo($admin, AdminPasswordResetNotification::class);
});

it('returns the same response for non-existent email to prevent enumeration', function () {
    $response = $this->postJson('/api/v1/admin/auth/forgot-password', [
        'email' => 'nonexistent@etigo.com',
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'If the email exists in our system, a password reset link has been sent.']);

    Notification::assertNothingSent();
});

it('does not send reset link for non-admin users', function () {
    User::factory()->passenger()->create([
        'email' => 'passenger@test.com',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/forgot-password', [
        'email' => 'passenger@test.com',
    ]);

    $response->assertOk();

    Notification::assertNothingSent();
});

it('resets password with a valid token', function () {
    $admin = User::factory()->admin(AdminRole::Operations)->create([
        'email' => 'reset@etigo.com',
    ]);

    $token = Password::broker()->createToken($admin);

    $response = $this->postJson('/api/v1/admin/auth/reset-password', [
        'token' => $token,
        'email' => 'reset@etigo.com',
        'password' => 'NewSecureP@ss1',
        'password_confirmation' => 'NewSecureP@ss1',
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Password has been reset successfully.']);

    expect(Hash::check('NewSecureP@ss1', $admin->fresh()->password))->toBeTrue();
});

it('rejects password reset with invalid token', function () {
    User::factory()->admin(AdminRole::Operations)->create([
        'email' => 'invalid-token@etigo.com',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/reset-password', [
        'token' => 'invalid-token-value',
        'email' => 'invalid-token@etigo.com',
        'password' => 'NewSecureP@ss1',
        'password_confirmation' => 'NewSecureP@ss1',
    ]);

    $response->assertUnprocessable();
});

it('revokes all tokens on password reset', function () {
    $admin = User::factory()->admin(AdminRole::Operations)->create([
        'email' => 'revoke-tokens@etigo.com',
    ]);

    $admin->createToken('admin-auth', ['admin'])->plainTextToken;
    $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    expect($admin->tokens()->count())->toBe(2);

    $token = Password::broker()->createToken($admin);

    $this->postJson('/api/v1/admin/auth/reset-password', [
        'token' => $token,
        'email' => 'revoke-tokens@etigo.com',
        'password' => 'NewSecureP@ss1',
        'password_confirmation' => 'NewSecureP@ss1',
    ]);

    expect($admin->tokens()->count())->toBe(0);
});
