<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AdminLoginCodeNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['auth.admin_two_factor.enabled' => true]);
    Notification::fake();
    $this->admin = User::factory()->admin()->create(['email' => 'adaeze@etigo.com']);
});

function startAdminChallenge(): string
{
    return test()->postJson('/api/v1/admin/auth/login', ['email' => 'adaeze@etigo.com', 'password' => 'password'])
        ->assertOk()
        ->json('challenge_token');
}

function sentAdminCode(User $admin): string
{
    $code = null;
    Notification::assertSentTo($admin, AdminLoginCodeNotification::class, function ($notification) use (&$code) {
        $code = $notification->code;

        return true;
    });

    return $code;
}

it('emails a code instead of a token when two-step sign-in is on', function () {
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'adaeze@etigo.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('two_factor_required', true)
        ->assertJsonPath('email_hint', 'a***e@etigo.com')
        ->assertJsonMissingPath('token')
        ->assertJsonStructure(['challenge_token', 'expires_at']);

    Notification::assertSentTo($this->admin, AdminLoginCodeNotification::class);
    expect($this->admin->tokens()->count())->toBe(0);
});

it('gives a token for the right code, once', function () {
    $challenge = startAdminChallenge();

    $this->postJson('/api/v1/admin/auth/login/verify', ['challenge_token' => $challenge, 'code' => sentAdminCode($this->admin)])
        ->assertOk()
        ->assertJsonPath('two_factor_required', false)
        ->assertJsonPath('user.email', 'adaeze@etigo.com')
        ->assertJsonStructure(['token']);

    expect(AuditLog::where('event', 'admin_logged_in')->sole()->new_values)->toBe(['two_factor' => true]);

    $this->postJson('/api/v1/admin/auth/login/verify', ['challenge_token' => $challenge, 'code' => sentAdminCode($this->admin)])
        ->assertStatus(422);
});

it('uses a random code in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $challenge = startAdminChallenge();
    $code = sentAdminCode($this->admin);

    expect($code)->toMatch('/^\d{6}$/');

    $this->postJson('/api/v1/admin/auth/login/verify', ['challenge_token' => $challenge, 'code' => $code])->assertOk();
});

it('rejects a wrong code and ends the sign-in after five wrong tries', function () {
    $challenge = startAdminChallenge();

    foreach (range(1, 5) as $try) {
        $this->postJson('/api/v1/admin/auth/login/verify', ['challenge_token' => $challenge, 'code' => '000000'])
            ->assertStatus(422);
    }

    $this->postJson('/api/v1/admin/auth/login/verify', ['challenge_token' => $challenge, 'code' => '123456'])
        ->assertStatus(429);

    // The challenge is gone, even with the right code.
    $this->postJson('/api/v1/admin/auth/login/verify', ['challenge_token' => $challenge, 'code' => '123456'])
        ->assertStatus(422);
});

it('rejects an expired code', function () {
    $challenge = startAdminChallenge();
    $this->travel(11)->minutes();

    $this->postJson('/api/v1/admin/auth/login/verify', ['challenge_token' => $challenge, 'code' => sentAdminCode($this->admin)])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This sign-in code has expired. Sign in again.');
});

it('refuses a deactivated admin at the code step', function () {
    $challenge = startAdminChallenge();
    $this->admin->update(['is_active' => false]);

    $this->postJson('/api/v1/admin/auth/login/verify', ['challenge_token' => $challenge, 'code' => sentAdminCode($this->admin)])
        ->assertForbidden();
});

it('resends a code after the cooldown, up to three times', function () {
    $challenge = startAdminChallenge();

    $this->postJson('/api/v1/admin/auth/login/resend', ['challenge_token' => $challenge])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    foreach (range(1, 3) as $resend) {
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/admin/auth/login/resend', ['challenge_token' => $challenge])->assertOk();
    }

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/admin/auth/login/resend', ['challenge_token' => $challenge])->assertStatus(429);

    Notification::assertSentToTimes($this->admin, AdminLoginCodeNotification::class, 4);
});

it('still signs in with the password alone when two-step sign-in is off', function () {
    config(['auth.admin_two_factor.enabled' => false]);

    $this->postJson('/api/v1/admin/auth/login', ['email' => 'adaeze@etigo.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('two_factor_required', false)
        ->assertJsonStructure(['token']);

    Notification::assertNothingSent();
});

it('sends no code for a wrong password', function () {
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'adaeze@etigo.com', 'password' => 'wrong-password'])
        ->assertUnauthorized();

    Notification::assertNothingSent();
});

it('renders the code email', function () {
    $html = (string) (new AdminLoginCodeNotification('482910', 10))->toMail($this->admin)->render();

    expect($html)->toContain('482910')
        ->toContain('adaeze@etigo.com')
        ->toContain('expires in 10 minutes');
});
