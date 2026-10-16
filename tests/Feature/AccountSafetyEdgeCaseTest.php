<?php

use App\Contracts\SmsGateway;
use App\Contracts\WalletGateway;
use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Enums\NotificationCategory;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\City;
use App\Models\Driver;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\PayoutAccountChangedNotification;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

function verifyPhoneAs(object $test, string $phone, string $type): void
{
    $test->postJson('/api/v1/auth/otp/send', ['phone' => $phone])->assertOk();
    $test->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => '123456', 'type' => $type])->assertOk();
}

function registerAs(object $test, string $phone, string $type): TestResponse
{
    return $test->postJson('/api/v1/auth/register/complete', [
        'phone' => $phone,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'type' => $type,
        'city_id' => $type === 'driver' ? City::factory()->create()->id : null,
    ]);
}

// === Registration is bound to the verified account type ===

it('refuses to open a driver account with an OTP verified for a passenger sign-in', function () {
    verifyPhoneAs($this, '+2348011112222', 'passenger');

    registerAs($this, '+2348011112222', 'driver')
        ->assertForbidden()
        ->assertJsonPath('message', 'Phone number not verified. Please verify your phone first.');

    expect(User::where('phone', '+2348011112222')->exists())->toBeFalse();
});

it('lets a verified OTP complete exactly one registration', function () {
    verifyPhoneAs($this, '+2348033334444', 'passenger');

    registerAs($this, '+2348033334444', 'passenger')->assertCreated();
    User::where('phone', '+2348033334444')->delete();

    registerAs($this, '+2348033334444', 'passenger')->assertForbidden();
});

// === Token lifetimes per audience ===

it('keeps passenger app sessions alive for days', function () {
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('passenger-auth', ['passenger'])->plainTextToken;

    $this->travel(3)->days();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('expires admin dashboard sessions after the admin token lifetime', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $this->travel(config('sanctum.admin_token_expiration') + 1)->minutes();

    $this->withToken($token)->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
});

// === Vehicle class and fleet vehicle controls ===

it('stops an approved driver from changing their own vehicle class', function () {
    $driver = Driver::factory()->approved()->create();
    $vehicle = Vehicle::factory()->create(['driver_id' => $driver->id]);
    $premium = VehicleClass::factory()->create();

    $this->actingAs($driver->user)
        ->putJson('/api/v1/driver/vehicle', ['vehicle_class_id' => $premium->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vehicle_class_id']);

    expect($vehicle->fresh()->vehicle_class_id)->not->toBe($premium->id);
});

it('lets a driver still onboarding choose a different vehicle class', function () {
    $driver = Driver::factory()->create(['status' => DriverStatus::Onboarding]);
    Vehicle::factory()->create(['driver_id' => $driver->id]);
    $comfort = VehicleClass::factory()->create();

    $this->actingAs($driver->user)
        ->putJson('/api/v1/driver/vehicle', ['vehicle_class_id' => $comfort->id])
        ->assertOk();

    expect($driver->vehicle->fresh()->vehicle_class_id)->toBe($comfort->id);
});

it('lets operations change a driver\'s vehicle class and audits it', function () {
    $operations = User::factory()->admin(AdminRole::Operations)->create();
    $driver = Driver::factory()->approved()->create();
    $vehicle = Vehicle::factory()->create(['driver_id' => $driver->id]);
    $premium = VehicleClass::factory()->create();

    $this->actingAs($operations)
        ->putJson("/api/v1/admin/drivers/{$driver->id}/vehicle-class", ['vehicle_class_id' => $premium->id])
        ->assertOk()
        ->assertJsonPath('vehicle.vehicle_class_id', $premium->id);

    expect(AuditLog::where('auditable_id', $vehicle->id)->where('event', 'vehicle_class_changed')->exists())->toBeTrue();
});

it('forbids a fleet driver from editing the E-tiGo vehicle', function () {
    $driver = Driver::factory()->approved()->create();
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id, 'plate_number' => 'ETG-001AA']);

    $this->actingAs($driver->user)
        ->putJson('/api/v1/driver/vehicle', ['plate_number' => 'XYZ-999ZZ'])
        ->assertForbidden();

    expect($vehicle->fresh()->plate_number)->toBe('ETG-001AA');
});

it('rejects plate verification for a plate other than the registered vehicle\'s', function () {
    $driver = Driver::factory()->create();
    Vehicle::factory()->create(['driver_id' => $driver->id, 'plate_number' => 'LAG-123AB']);

    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/kyc/verify-vehicle', ['plate_number' => 'ABJ-777CD'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plate_number' => 'The plate number must match your registered vehicle.']);

    $this->assertDatabaseMissing('kyc_verifications', ['driver_id' => $driver->id, 'type' => 'vehicle_plate']);
});

it('disables the manual KYC bypass in production', function () {
    $admin = User::factory()->admin()->create();
    $driver = Driver::factory()->create(['status' => DriverStatus::PendingReview]);
    $this->app->detectEnvironment(fn () => 'production');

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding")
        ->assertForbidden()
        ->assertJsonPath('message', 'Manual onboarding and KYC bypass is disabled in production.');

    expect($driver->fresh()->status)->toBe(DriverStatus::PendingReview);
});

// === Adding or changing the payout bank account needs a fresh OTP ===

it('requires a verification code to add the first payout bank account', function () {
    $driver = Driver::factory()->approved()->create();
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldNotReceive('resolveAccountNumber');
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account', ['bank_code' => '044', 'account_number' => '0987654321'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['otp_code']);

    expect($driver->bankAccounts()->exists())->toBeFalse();
});

it('sends a bank account code to a driver with no account on file', function () {
    $driver = Driver::factory()->approved()->create();
    $this->app->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));

    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account/otp')
        ->assertOk()
        ->assertJsonPath('message', 'Verification code sent to your phone.');
});

it('refuses a wrong code when adding the first payout bank account', function () {
    $driver = Driver::factory()->approved()->create();
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('resolveAccountNumber')->once()
        ->andReturn(['account_number' => '0987654321', 'account_name' => 'ADA OBI']);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($driver->user)->postJson('/api/v1/driver/bank-account/otp')->assertOk();
    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account', ['bank_code' => '044', 'account_number' => '0987654321', 'otp_code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.otp_code.0', 'Invalid verification code.');

    expect($driver->bankAccounts()->exists())->toBeFalse();
});

it('requires a verification code to replace an existing payout bank account', function () {
    $driver = Driver::factory()->approved()->create();
    $original = BankAccount::factory()->create(['driver_id' => $driver->id]);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldNotReceive('resolveAccountNumber');
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account', ['bank_code' => '044', 'account_number' => '0987654321'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['otp_code']);

    expect($original->fresh()->is_primary)->toBeTrue();
});

it('replaces the payout bank account once the driver confirms the code', function () {
    Notification::fake();
    $driver = Driver::factory()->approved()->create();
    BankAccount::factory()->create(['driver_id' => $driver->id]);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('resolveAccountNumber')->once()
        ->andReturn(['account_number' => '0987654321', 'account_name' => 'ADA OBI']);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($driver->user)->postJson('/api/v1/driver/bank-account/otp')->assertOk();
    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account', ['bank_code' => '044', 'account_number' => '0987654321', 'otp_code' => '123456'])
        ->assertCreated();

    expect($driver->primaryBankAccount()->value('account_number'))->toBe('0987654321');
});

it('keeps the code usable when Paystack cannot verify the account', function () {
    Notification::fake();
    $driver = Driver::factory()->approved()->create();
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('resolveAccountNumber')->with('0000000000', '044')->once()
        ->andThrow(new RuntimeException('Account not found'));
    $gateway->shouldReceive('resolveAccountNumber')->with('0987654321', '044')->once()
        ->andReturn(['account_number' => '0987654321', 'account_name' => 'ADA OBI']);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($driver->user)->postJson('/api/v1/driver/bank-account/otp')->assertOk();
    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account', ['bank_code' => '044', 'account_number' => '0000000000', 'otp_code' => '123456'])
        ->assertUnprocessable();
    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account', ['bank_code' => '044', 'account_number' => '0987654321', 'otp_code' => '123456'])
        ->assertCreated();
});

it('alerts the driver when the payout bank account is added or replaced', function (bool $hadAccount) {
    Notification::fake();
    $driver = Driver::factory()->approved()->create();
    if ($hadAccount) {
        BankAccount::factory()->create(['driver_id' => $driver->id]);
    }
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('resolveAccountNumber')->once()
        ->andReturn(['account_number' => '0987654321', 'account_name' => 'ADA OBI']);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($driver->user)->postJson('/api/v1/driver/bank-account/otp')->assertOk();
    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account', ['bank_code' => '044', 'account_number' => '0987654321', 'otp_code' => '123456'])
        ->assertCreated();

    Notification::assertSentTo(
        $driver->user,
        PayoutAccountChangedNotification::class,
        fn (PayoutAccountChangedNotification $n, array $channels) => $n->replacedAnother === $hadAccount
            && in_array(SmsChannel::class, $channels, true),
    );
})->with(['first account' => false, 'replacement' => true]);

it('does not alert the driver when the bank account is not saved', function () {
    Notification::fake();
    $driver = Driver::factory()->approved()->create();
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('resolveAccountNumber')->once()
        ->andReturn(['account_number' => '0987654321', 'account_name' => 'ADA OBI']);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($driver->user)->postJson('/api/v1/driver/bank-account/otp')->assertOk();
    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account', ['bank_code' => '044', 'account_number' => '0987654321', 'otp_code' => '000000'])
        ->assertUnprocessable();

    Notification::assertNothingSent();
});

it('keeps the saved bank account when the change alert cannot be queued', function () {
    Exceptions::fake();
    $driver = Driver::factory()->approved()->create();
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('resolveAccountNumber')->once()
        ->andReturn(['account_number' => '0987654321', 'account_name' => 'ADA OBI']);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($driver->user)->postJson('/api/v1/driver/bank-account/otp')->assertOk();

    $dispatcher = Mockery::mock(NotificationDispatcher::class);
    $dispatcher->shouldReceive('send')->andThrow(new RuntimeException('Queue unavailable'));
    $this->app->instance(NotificationDispatcher::class, $dispatcher);

    $this->actingAs($driver->user)
        ->postJson('/api/v1/driver/bank-account', ['bank_code' => '044', 'account_number' => '0987654321', 'otp_code' => '123456'])
        ->assertCreated();

    expect($driver->bankAccounts()->where('account_number', '0987654321')->exists())->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});

it('words the payout account alert without the full account number', function () {
    $account = BankAccount::factory()->make(['account_number' => '0987654321', 'account_name' => 'ADA OBI']);
    $user = User::factory()->make();

    $changed = new PayoutAccountChangedNotification($account, replacedAnother: true);
    $added = new PayoutAccountChangedNotification($account, replacedAnother: false);

    expect($changed->toSms($user))
        ->toBe('E-tiGo: Your payout bank account was changed to ADA OBI, account ending 4321. If you did not do this, contact E-tiGo support immediately.')
        ->not->toContain('0987654321')
        ->and($added->toArray($user)['body'])->toStartWith('Your payout bank account was set to ADA OBI')
        ->and($added->toArray($user)['type'])->toBe('payout_account_changed');
});

it('sends the payout account alert by SMS even when payment notifications are switched off', function () {
    $driver = Driver::factory()->approved()->create();
    NotificationPreference::create([
        'user_id' => $driver->user_id,
        'category' => NotificationCategory::Payments,
        'push_enabled' => false,
        'in_app_enabled' => false,
    ]);
    $sms = Mockery::mock(SmsGateway::class);
    $sms->shouldReceive('send')->once()
        ->with($driver->user->phone, Mockery::on(fn (string $text) => str_contains($text, 'account ending 4321')))
        ->andReturnTrue();
    $this->app->instance(SmsGateway::class, $sms);

    $account = BankAccount::factory()->create(['driver_id' => $driver->id, 'account_number' => '0987654321']);
    $driver->user->notify(new PayoutAccountChangedNotification($account, replacedAnother: true));

    expect($driver->user->notifications()->count())->toBe(0);
});

it('retries the payout account alert when the SMS is not delivered', function () {
    $sms = Mockery::mock(SmsGateway::class);
    $sms->shouldReceive('send')->once()->andReturnFalse();
    $user = User::factory()->make(['phone' => '+2348012345678']);
    $notification = new PayoutAccountChangedNotification(BankAccount::factory()->make(), replacedAnother: false);

    expect(fn () => (new SmsChannel($sms))->send($user, $notification))->toThrow(RuntimeException::class);
});
