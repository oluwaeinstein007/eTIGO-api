<?php

use App\Contracts\SmsGateway;
use App\Enums\BalanceDueReason;
use App\Enums\BalanceDueStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\PurgeDeletedAccountsJob;
use App\Models\City;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Models\PassengerBalanceDue;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AccountDeletionService;
use App\Services\OtpService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->smsGateway = Mockery::mock(SmsGateway::class);
    $this->app->instance(SmsGateway::class, $this->smsGateway);
});

it('sends an OTP to a valid phone number', function () {
    $this->smsGateway->shouldReceive('sendOtp')->never();

    $response = $this->postJson('/api/v1/auth/otp/send', [
        'phone' => '+2341234567890',
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Verification code sent.'])
        ->assertJsonStructure(['expires_at']);

    $this->assertDatabaseHas('otp_codes', [
        'phone' => '+2341234567890',
        'purpose' => 'login',
    ]);
});

it('rejects OTP request with invalid phone format', function () {
    $response = $this->postJson('/api/v1/auth/otp/send', [
        'phone' => '1234567890',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
});

it('rate limits OTP requests within cooldown period', function () {
    $this->smsGateway->shouldReceive('sendOtp')->never();

    $this->postJson('/api/v1/auth/otp/send', ['phone' => '+2341234567890']);

    $response = $this->postJson('/api/v1/auth/otp/send', [
        'phone' => '+2341234567890',
    ]);

    $response->assertStatus(429)
        ->assertJson(['message' => 'Please wait before requesting another code.']);
});

it('logs in an existing user after OTP verification', function () {
    $user = User::factory()->passenger()->create([
        'phone' => '+2341234567890',
    ]);

    $code = '123456';
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', $code),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => $code,
        'type' => 'passenger',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'user', 'token'])
        ->assertJson([
            'is_new_user' => false,
            'user' => [
                'phone' => '+2341234567890',
                'type' => 'passenger',
            ],
        ]);
});

it('returns is_new_user when phone is not registered', function () {
    $code = '123456';
    OtpCode::create([
        'phone' => '+2349999999999',
        'code' => hash('sha256', $code),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2349999999999',
        'code' => $code,
        'type' => 'passenger',
    ]);

    $response->assertOk()
        ->assertJson([
            'is_new_user' => true,
            'phone' => '+2349999999999',
        ]);
});

it('rejects OTP verification with wrong code', function () {
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', '123456'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => '000000',
        'type' => 'passenger',
    ]);

    $response->assertUnprocessable()
        ->assertJson(['message' => 'Invalid verification code.']);
});

it('rejects OTP verification for deactivated user', function () {
    User::factory()->passenger()->inactive()->create([
        'phone' => '+2341234567890',
    ]);

    $code = '123456';
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', $code),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => $code,
        'type' => 'passenger',
    ]);

    $response->assertForbidden();
});

it('completes registration for a new passenger after OTP verification', function () {
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', '123456'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
        'verified_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/auth/register/complete', [
        'phone' => '+2341234567890',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'type' => 'passenger',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'user', 'token'])
        ->assertJson([
            'user' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'type' => 'passenger',
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'phone' => '+2341234567890',
        'type' => 'passenger',
    ]);
});

it('completes registration for a new driver with driver record', function () {
    Storage::fake(config('filesystems.uploads'));

    OtpCode::create([
        'phone' => '+2349876543210',
        'code' => hash('sha256', '123456'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
        'verified_at' => now(),
    ]);

    $city = City::factory()->create();

    $response = $this->post('/api/v1/auth/register/complete', [
        'phone' => '+2349876543210',
        'first_name' => 'Jane',
        'last_name' => 'Driver',
        'type' => 'driver',
        'city_id' => $city->id,
        'profile_photo' => UploadedFile::fake()->create('profile.jpg', 100, 'image/jpeg'),
    ]);

    $response->assertCreated()
        ->assertJson([
            'user' => ['type' => 'driver'],
        ])
        ->assertJsonStructure(['user' => ['profile_photo_url']]);

    $user = User::where('phone', '+2349876543210')->first();
    expect($user->type)->toBe(UserType::Driver);
    expect($user->driver)->not->toBeNull();
    expect($user->driver->status->value)->toBe('onboarding');
    Storage::disk(config('filesystems.uploads'))->assertExists($user->profile_photo_path);
});

it('rejects registration without phone verification', function () {
    $response = $this->postJson('/api/v1/auth/register/complete', [
        'phone' => '+2341234567890',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'type' => 'passenger',
    ]);

    $response->assertForbidden()
        ->assertJson(['message' => 'Phone number not verified. Please verify your phone first.']);
});

it('returns current user via me endpoint', function () {
    $user = User::factory()->passenger()->create();
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonStructure(['user' => ['id', 'first_name', 'last_name', 'email', 'type']]);
});

it('allows authenticated user to logout', function () {
    $user = User::factory()->passenger()->create();
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/auth/logout');

    $response->assertOk()
        ->assertJson(['message' => 'Logged out successfully.']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('sends a random code with readable SMS text in production', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $this->smsGateway->shouldReceive('sendOtp')->once()
        ->withArgs(fn (string $phone, string $code, string $message) => $phone === '+2341234567890'
            && preg_match('/^\d{6}$/', $code) === 1
            && $message === "Your E-tiGo verification code is {$code}. It expires in 5 minutes. Do not share it with anyone.")
        ->andReturnTrue();

    $this->postJson('/api/v1/auth/otp/send', ['phone' => '+2341234567890'])
        ->assertOk()
        ->assertJson(['message' => 'Verification code sent.']);
});

it('names the bank account change in that code\'s text', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $this->smsGateway->shouldReceive('sendOtp')->once()
        ->withArgs(fn (string $phone, string $code, string $message) => str_starts_with($message, "Your E-tiGo code to change your payout bank account is {$code}."))
        ->andReturnTrue();

    expect(app(OtpService::class)->sendAuthOtp('+2341234567890', 'bank_account_change')['sent'])->toBeTrue();
});

it('expires the code when no channel accepts it', function () {
    $this->app->detectEnvironment(fn () => 'production');
    $this->smsGateway->shouldReceive('sendOtp')->once()->andReturnFalse();

    $this->postJson('/api/v1/auth/otp/send', ['phone' => '+2341234567890'])
        ->assertStatus(429)
        ->assertJson(['message' => 'Failed to send verification code. Please try again.']);

    expect(OtpCode::forPhone('+2341234567890')->active()->exists())->toBeFalse();
});

it('prevents passenger from deleting account while active ride exists', function () {
    $user = User::factory()->passenger()->create();
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    Ride::factory()->create([
        'passenger_id' => $user->id,
        'status' => RideStatus::InProgress->value,
    ]);

    $response = $this->withToken($token)
        ->deleteJson('/api/v1/auth/account');

    $response->assertStatus(422)
        ->assertJson(['message' => 'Cannot delete account while you have an active ride.']);
});

it('prevents passenger from deleting account with outstanding balance', function () {
    $user = User::factory()->passenger()->create();
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    PassengerBalanceDue::create([
        'passenger_id' => $user->id,
        'amount' => 1500,
        'status' => BalanceDueStatus::Outstanding,
        'reason' => BalanceDueReason::LateCancellationFee,
    ]);

    $response = $this->withToken($token)
        ->deleteJson('/api/v1/auth/account');

    $response->assertStatus(422)
        ->assertJson(['message' => 'Cannot delete account while you have an outstanding balance.']);
});

it('prevents driver from deleting account while active trip exists', function () {
    $driverUser = User::factory()->driver()->create();
    Driver::factory()->create([
        'user_id' => $driverUser->id,
        'is_online' => true,
    ]);
    $token = $driverUser->createToken('test', ['driver'])->plainTextToken;

    Ride::factory()->create([
        'driver_id' => $driverUser->id,
        'status' => RideStatus::InProgress->value,
    ]);

    $response = $this->withToken($token)
        ->deleteJson('/api/v1/auth/account');

    $response->assertStatus(422)
        ->assertJson(['message' => 'Cannot delete account while you have an active trip.']);
});

it('rejects admin deleting account via auth account endpoint', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->deleteJson('/api/v1/auth/account');

    $response->assertStatus(403)
        ->assertJson(['message' => 'Admin accounts cannot be deleted through this endpoint.']);
});

it('schedules a passenger account for deletion after the grace period and logs them out', function () {
    $this->travelTo(now()->setDate(2026, 11, 5)->setTime(10, 0));
    $user = User::factory()->passenger()->create();
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    $this->withToken($token)
        ->deleteJson('/api/v1/auth/account')
        ->assertOk()
        ->assertJson([
            'message' => 'Your account will be deleted on 5 December 2026. Log in again before then to keep it.',
            'grace_days' => 30,
        ])
        ->assertJsonPath('deletion_scheduled_for', now()->addDays(30)->toIso8601String());

    $this->assertDatabaseCount('personal_access_tokens', 0);
    $fresh = $user->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->phone)->toBe($user->phone)
        ->and($fresh->deletion_requested_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['auditable_id' => $user->id, 'event' => 'account_deletion_requested']);
});

it('cancels a pending deletion when the user logs in again within the grace period', function () {
    $user = User::factory()->passenger()->create(['phone' => '+2341234567890']);
    $user->forceFill(['deletion_requested_at' => now()->subDays(10)])->save();

    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', '123456'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => '123456',
        'type' => 'passenger',
    ])->assertOk()->assertJson(['account_restored' => true]);

    expect($user->fresh()->deletion_requested_at)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['auditable_id' => $user->id, 'event' => 'account_deletion_cancelled']);

    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));
    $this->travel(31)->days();
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));

    expect($user->fresh())->not->toBeNull()->and($user->fresh()->first_name)->not->toBe('Deleted');
});

it('reports account_restored false on an ordinary login', function () {
    User::factory()->passenger()->create(['phone' => '+2341234567890']);
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', '123456'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => '123456',
        'type' => 'passenger',
    ])->assertOk()->assertJson(['account_restored' => false]);
});

it('keeps the account during the grace period and deletes it once the period ends', function () {
    $user = User::factory()->passenger()->create();
    app(AccountDeletionService::class)->requestDeletion($user);

    $this->travel(29)->days();
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));
    expect(User::find($user->id))->not->toBeNull();

    $this->travel(2)->days();
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));
    expect(User::find($user->id))->toBeNull();
});

it('anonymises an account with ride history when the grace period ends', function () {
    $user = User::factory()->passenger()->create();
    Ride::factory()->create([
        'passenger_id' => $user->id,
        'status' => RideStatus::Completed->value,
    ]);
    app(AccountDeletionService::class)->requestDeletion($user);

    $this->travel(31)->days();
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));

    $fresh = User::find($user->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_active)->toBeFalse()
        ->and($fresh->first_name)->toBe('Deleted')
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->email)->toBeNull()
        ->and($fresh->deletion_requested_at)->toBeNull();
});

it('postpones the purge while something blocks deletion', function () {
    $user = User::factory()->passenger()->create();
    app(AccountDeletionService::class)->requestDeletion($user);

    PassengerBalanceDue::create([
        'passenger_id' => $user->id,
        'amount' => 1500,
        'status' => BalanceDueStatus::Outstanding,
        'reason' => BalanceDueReason::LateCancellationFee,
    ]);

    $this->travel(31)->days();
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()->deletion_requested_at)->not->toBeNull();
});

it('takes a driver offline on request and frees their vehicle at the purge', function () {
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->create([
        'user_id' => $driverUser->id,
        'is_online' => true,
    ]);
    $vehicle = Vehicle::factory()->create(['driver_id' => $driver->id]);
    $token = $driverUser->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/auth/account')->assertOk();

    expect($driver->fresh()->is_online)->toBeFalse()
        ->and($vehicle->fresh()->driver_id)->toBe($driver->id);

    $this->travel(31)->days();
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));

    expect(User::find($driverUser->id))->toBeNull()
        ->and($vehicle->fresh()->driver_id)->toBeNull();
});
