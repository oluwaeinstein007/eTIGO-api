<?php

use App\Contracts\MapsGateway;
use App\Enums\AdminRole;
use App\Enums\UserType;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\PricingConfig;
use App\Models\User;
use App\Models\VehicleClass;
use App\Rules\CashPaymentsAllowed;
use Illuminate\Support\Facades\Queue;

/*
| Cash payments are off by default (the client prefers card and wallet).
| Finance can switch them on; rides already booked in cash still finish.
*/

beforeEach(function () {
    Queue::fake();

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->token = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;
    $this->finance = User::factory()->admin(AdminRole::Finance)->create();

    $this->vehicleClass = VehicleClass::factory()->create();
    $this->lagos = City::factory()->create([
        'name' => 'Lagos',
        'state' => 'Lagos',
        'boundary' => ['type' => 'Polygon', 'coordinates' => [[[3.18, 6.40], [3.75, 6.40], [3.75, 6.69], [3.18, 6.69], [3.18, 6.40]]]],
    ]);
    $this->lagos->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);
    PricingConfig::factory()->create([
        'city_id' => $this->lagos->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $this->finance->id,
    ]);

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getDistanceAndDuration')->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $maps->shouldReceive('reverseGeocode')->andReturn(['address' => 'Somewhere, Lagos']);
    $this->app->instance(MapsGateway::class, $maps);

    $this->book = fn (array $overrides = []) => $this->withToken($this->token)->postJson('/api/v1/rides', [
        'vehicle_class_id' => $this->vehicleClass->id,
        'pickup_lat' => 6.6143,
        'pickup_lng' => 3.3577,
        'pickup_address' => 'Ikeja City Mall, Alausa',
        'destination_lat' => 6.4264,
        'destination_lng' => 3.4290,
        'destination_address' => 'Eko Hotel, Victoria Island',
        'payment_method' => 'cash',
        ...$overrides,
    ]);
});

it('refuses cash for a booking while cash is off, and takes it once Finance switches it on', function () {
    ($this->book)()->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_method' => CashPaymentsAllowed::MESSAGE]);

    $this->app['auth']->forgetGuards();
    $this->withToken($this->finance->createToken('auth', ['admin'])->plainTextToken)
        ->putJson('/api/v1/admin/settings/wallet', ['cash_payments_enabled' => true])
        ->assertOk()
        ->assertJsonPath('settings.cash_payments_enabled', true);

    $this->app['auth']->forgetGuards();
    ($this->book)()->assertCreated()->assertJsonPath('ride.payment_method', 'cash');
});

it('refuses switching a scheduled booking to cash while cash is off', function () {
    AppSetting::setValue('wallet.cash_payments_enabled', 1, 'wallet');
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addDay()->setTime(9, 0)->toIso8601String()])->assertCreated()->json('ride.id');
    AppSetting::setValue('wallet.cash_payments_enabled', 0, 'wallet');

    $this->withToken($this->token)->putJson("/api/v1/rides/{$rideId}/scheduled", ['payment_method' => 'cash'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_method' => CashPaymentsAllowed::MESSAGE]);
});

it('keeps cash off for bookings for someone else unless both switches allow it', function () {
    AppSetting::setValue('third_party.allow_cash', 1, 'third_party');

    ($this->book)(['rider_name' => 'Mrs Folake Adeyemi', 'rider_phone' => '+2348031234567', 'rider_age_confirmed' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method');

    $this->getJson('/api/v1/config')->assertOk()
        ->assertJsonPath('third_party_bookings.payment_methods', ['wallet', 'card']);
});

it('tells the apps which payment methods to offer', function () {
    $this->getJson('/api/v1/config')->assertOk()
        ->assertJsonPath('payments.cash_enabled', false)
        ->assertJsonPath('payments.methods', ['card', 'wallet']);

    AppSetting::setValue('wallet.cash_payments_enabled', 1, 'wallet');

    $this->getJson('/api/v1/config')->assertOk()
        ->assertJsonPath('payments.cash_enabled', true)
        ->assertJsonPath('payments.methods', ['card', 'wallet', 'cash']);
});
