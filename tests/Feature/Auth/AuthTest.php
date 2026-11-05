<?php

use App\Contracts\SmsGateway;
use App\Enums\BalanceDueReason;
use App\Enums\BalanceDueStatus;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\PurgeDeletedAccountsJob;
use App\Models\Account;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStall;
use App\Models\EvReservation;
use App\Models\FleetAgreement;
use App\Models\OtpCode;
use App\Models\PassengerBalanceDue;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AccountDeletionService;
use App\Services\OtpService;
use App\Services\ScheduledRideReservationService;
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

it('keeps the date promised at request time when the grace setting changes', function () {
    $user = User::factory()->passenger()->create();
    $scheduledFor = app(AccountDeletionService::class)->requestDeletion($user);
    expect($scheduledFor->toDateString())->toBe(now()->addDays(30)->toDateString());

    config(['accounts.deletion_grace_days' => 1]);
    $this->travel(2)->days();
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));

    expect(User::find($user->id))->not->toBeNull();
});

it('uses 30 days when the grace setting is not a whole number of at least 1', function (mixed $value) {
    config(['accounts.deletion_grace_days' => $value]);

    expect(AccountDeletionService::graceDays())->toBe(30);
})->with(['', 'abc', 0, -5, '7.5']);

it('does not purge an account restored after the purge loaded it', function () {
    $user = User::factory()->passenger()->create();
    $service = app(AccountDeletionService::class);
    $service->requestDeletion($user);
    $this->travel(31)->days();

    $stale = User::find($user->id);
    $service->cancelDeletion(User::find($user->id));

    expect($service->purge($stale))->toBeFalse()
        ->and(User::find($user->id))->not->toBeNull();
});

it('releases a reserved EV stall when a driver asks for deletion', function () {
    $driverUser = User::factory()->driver()->create();
    Driver::factory()->create(['user_id' => $driverUser->id]);
    $stall = EvChargingStall::factory()->reserved()->create();
    $reservation = EvReservation::factory()->create([
        'driver_id' => $driverUser->id,
        'station_id' => $stall->station_id,
        'stall_id' => $stall->id,
        'status' => EvReservationStatus::Reserved,
    ]);

    app(AccountDeletionService::class)->requestDeletion($driverUser);

    expect($reservation->fresh()->status)->toBe(EvReservationStatus::Cancelled)
        ->and($stall->fresh()->status)->toBe(EvStallStatus::Available);
});

it('cannot reserve a scheduled ride for a driver pending deletion', function () {
    $driverUser = User::factory()->driver()->create();
    Driver::factory()->create(['user_id' => $driverUser->id, 'status' => DriverStatus::Approved]);
    $driverUser->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(30)])->save();
    $ride = Ride::factory()->create(['status' => RideStatus::Scheduled]);

    $errors = app(ScheduledRideReservationService::class)->reservationErrors($driverUser, $ride, byAdmin: true);

    expect($errors)->toBe(['This driver has asked for their account to be deleted.']);
});

it('uses a configured grace period for the date, the response and the purge', function () {
    config(['accounts.deletion_grace_days' => 7]);
    $this->travelTo(Carbon\Carbon::parse('2026-11-05 10:00:00'));
    $user = User::factory()->passenger()->create();
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/auth/account')
        ->assertOk()
        ->assertJson(['grace_days' => 7])
        ->assertJsonPath('deletion_scheduled_for', '2026-11-12T10:00:00+00:00');

    $this->travelTo(Carbon\Carbon::parse('2026-11-12 09:59:59'));
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));
    expect(User::find($user->id))->not->toBeNull();

    // Purged on the scheduled moment itself.
    $this->travelTo(Carbon\Carbon::parse('2026-11-12 10:00:00'));
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));
    expect(User::find($user->id))->toBeNull();
});

it('anonymises instead when other records refuse the delete', function () {
    $driverUser = User::factory()->driver()->create();
    Driver::factory()->create(['user_id' => $driverUser->id]);
    // No rides, but an old EV reservation still points at the user.
    EvReservation::factory()->create([
        'driver_id' => $driverUser->id,
        'status' => EvReservationStatus::Cancelled,
    ]);
    app(AccountDeletionService::class)->requestDeletion($driverUser);

    $this->travel(31)->days();
    (new PurgeDeletedAccountsJob)->handle(app(AccountDeletionService::class));

    $fresh = User::find($driverUser->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->first_name)->toBe('Deleted')
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->deletion_scheduled_for)->toBeNull();
});

it('refuses deletion while the passenger wallet holds money', function () {
    $user = User::factory()->passenger()->create();
    Account::factory()->passengerWallet()->withBalance(250000)->create(['owner_id' => $user->id]);
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/auth/account')
        ->assertStatus(422)
        ->assertJson(['message' => 'Cannot delete account while your wallet has ₦2,500.00. Use it on a ride or contact support for a refund first.']);
});

it('refuses deletion while a driver has earnings or owes money', function (int $pending, int $available, string $message) {
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->create(['user_id' => $driverUser->id]);
    $owner = ['owner_type' => $driver->getMorphClass(), 'owner_id' => $driver->id];
    Account::factory()->driverEarningsPending()->withBalance($pending)->create($owner);
    Account::factory()->driverEarningsAvailable()->withBalance($available)->create($owner);

    expect(app(AccountDeletionService::class)->blockingReason($driverUser))->toBe($message);
})->with([
    'pending earnings' => [150000, 0, 'Cannot delete account while you have ₦1,500.00 in earnings. Withdraw them first (earnings still settling become available within a few hours).'],
    'available earnings' => [0, 50000, 'Cannot delete account while you have ₦500.00 in earnings. Withdraw them first (earnings still settling become available within a few hours).'],
    'money owed' => [0, -30000, 'Cannot delete account while you owe E-tiGo ₦300.00. Settle it first.'],
]);

it('refuses deletion while a driver has an active or paused fleet agreement', function (string $state) {
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->create(['user_id' => $driverUser->id]);
    $factory = FleetAgreement::factory();
    ($state === 'paused' ? $factory->paused() : $factory)->create(['driver_id' => $driver->id]);

    expect(app(AccountDeletionService::class)->blockingReason($driverUser))
        ->toBe('Cannot delete account while you have a fleet vehicle agreement. Ask your fleet manager to end it first.');
})->with(['active', 'paused']);

it('allows deletion once a driver fleet agreement has ended', function () {
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->create(['user_id' => $driverUser->id]);
    FleetAgreement::factory()->terminated()->create(['driver_id' => $driver->id]);

    expect(app(AccountDeletionService::class)->blockingReason($driverUser))->toBeNull();
});
