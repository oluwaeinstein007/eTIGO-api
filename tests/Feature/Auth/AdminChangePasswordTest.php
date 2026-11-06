<?php

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AdminPasswordChangedNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

const CHANGE_PASSWORD_URL = '/api/v1/admin/auth/change-password';

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin(AdminRole::Operations)->create(['password' => 'OldPassword1']);
});

function changePasswordPayload(array $overrides = []): array
{
    return array_merge([
        'current_password' => 'OldPassword1',
        'password' => 'NewPassword2',
        'password_confirmation' => 'NewPassword2',
    ], $overrides);
}

it('changes the password when the current one is correct', function () {
    $token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $this->withToken($token)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload())
        ->assertOk()
        ->assertJson(['message' => 'Password changed successfully.']);

    expect(Hash::check('NewPassword2', $this->admin->fresh()->password))->toBeTrue();
    Notification::assertSentTo($this->admin, AdminPasswordChangedNotification::class);
    expect(AuditLog::where('event', 'admin_password_changed')->count())->toBe(1);
});

it('keeps the current session and signs out every other one', function () {
    $current = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;
    $this->admin->createToken('other-browser', ['admin']);
    $this->admin->createToken('phone', ['admin']);

    $this->withToken($current)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload())->assertOk();

    expect($this->admin->tokens()->count())->toBe(1);
    $this->withToken($current)->getJson('/api/v1/admin/auth/me')->assertOk();
});

it('voids a pending password reset link', function () {
    $resetToken = Password::broker()->createToken($this->admin);
    $token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $this->withToken($token)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload())->assertOk();

    expect(Password::broker()->tokenExists($this->admin->fresh(), $resetToken))->toBeFalse();
});

it('rejects a wrong current password and leaves everything unchanged', function () {
    $token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;
    $this->admin->createToken('other-browser', ['admin']);

    $this->withToken($token)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload(['current_password' => 'WrongPassword']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    expect(Hash::check('OldPassword1', $this->admin->fresh()->password))->toBeTrue();
    expect($this->admin->tokens()->count())->toBe(2);
    Notification::assertNothingSent();
    expect(AuditLog::where('event', 'admin_password_change_failed')->count())->toBe(1);
});

it('rejects a new password that matches the current one', function () {
    $token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $this->withToken($token)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload([
        'password' => 'OldPassword1',
        'password_confirmation' => 'OldPassword1',
    ]))->assertStatus(422)->assertJsonValidationErrors('password');
});

it('validates the new password', function (array $overrides, string $field) {
    $token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $this->withToken($token)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload($overrides))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'not confirmed' => [['password_confirmation' => 'Different3'], 'password'],
    'missing current' => [['current_password' => ''], 'current_password'],
    'longer than 72 bytes' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
]);

it('requires authentication', function () {
    $this->postJson(CHANGE_PASSWORD_URL, changePasswordPayload())->assertUnauthorized();
});

it('is for admin accounts only', function () {
    $passenger = User::factory()->passenger()->create(['password' => 'OldPassword1']);
    $token = $passenger->createToken('passenger')->plainTextToken;

    $this->withToken($token)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload())->assertForbidden();

    expect(Hash::check('OldPassword1', $passenger->fresh()->password))->toBeTrue();
});

it('lets the admin sign in with the new password and not the old one', function () {
    $token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;
    $this->withToken($token)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload())->assertOk();

    config(['admin.two_factor_enabled' => false]);
    $this->postJson('/api/v1/admin/auth/login', ['email' => $this->admin->email, 'password' => 'OldPassword1'])->assertUnauthorized();
    $this->postJson('/api/v1/admin/auth/login', ['email' => $this->admin->email, 'password' => 'NewPassword2'])->assertOk();
});

it('limits repeated attempts per admin', function () {
    $token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;
    RateLimiter::clear('admin:'.$this->admin->id);

    foreach (range(1, 5) as $i) {
        $this->withToken($token)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload(['current_password' => 'nope']))->assertStatus(422);
    }

    $this->withToken($token)->postJson(CHANGE_PASSWORD_URL, changePasswordPayload())->assertStatus(429);

    // Real requests start fresh; the test app would otherwise keep the first admin signed in.
    app('auth')->forgetGuards();

    $other = User::factory()->admin(AdminRole::Finance)->create(['password' => 'OldPassword1']);
    $this->withToken($other->createToken('admin-auth', ['admin'])->plainTextToken)
        ->postJson(CHANGE_PASSWORD_URL, changePasswordPayload())
        ->assertOk();
});
