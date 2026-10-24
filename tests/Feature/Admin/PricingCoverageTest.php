<?php

use App\Models\City;
use App\Models\PricingConfig;
use App\Models\User;
use App\Models\VehicleClass;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;
    $this->city = City::factory()->create([
        'name' => 'Lekki Test',
        'state' => 'Lagos',
        'boundary' => ['type' => 'Polygon', 'coordinates' => [[[3.45, 6.42], [3.60, 6.42], [3.60, 6.48], [3.45, 6.48], [3.45, 6.42]]]],
    ]);
    $this->economy = VehicleClass::factory()->create(['name' => 'economy', 'display_name' => 'Economy']);
    $this->comfort = VehicleClass::factory()->create(['name' => 'comfort', 'display_name' => 'Comfort']);

    $this->city->vehicleClasses()->attach([
        $this->economy->id => ['is_active' => true],
        $this->comfort->id => ['is_active' => true],
    ]);
});

function pricingPayload(array $overrides = []): array
{
    return [
        'base_fare' => 600,
        'per_km_rate' => 250,
        'per_minute_rate' => 40,
        'minimum_fare' => 1500,
        'effective_from' => now()->toIso8601String(),
        ...$overrides,
    ];
}

it('lists offered vehicle classes that have no pricing in effect', function () {
    PricingConfig::factory()->forCityAndClass($this->city, $this->economy)->create();
    PricingConfig::factory()->forCityAndClass($this->city, $this->comfort)->future()->create();

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/pricing/gaps')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('gaps.0.city_name', 'Lekki Test')
        ->assertJsonPath('gaps.0.vehicle_class_id', $this->comfort->id)
        ->assertJsonPath('gaps.0.vehicle_class_name', 'Comfort')
        ->assertJsonPath('gaps.0.city_is_active', true)
        ->assertJson(fn ($json) => $json->whereType('gaps.0.next_effective_from', 'string')->etc());
});

it('leaves classes that are turned off out of the gaps', function () {
    $this->comfort->update(['is_active' => false]);
    $this->city->vehicleClasses()->updateExistingPivot($this->economy->id, ['is_active' => false]);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/pricing/gaps')
        ->assertOk()
        ->assertJsonPath('total', 0);
});

it('filters gaps by city', function () {
    $other = City::factory()->create();
    $other->vehicleClasses()->attach($this->economy->id, ['is_active' => true]);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/pricing/gaps?city_id={$other->id}")
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('gaps.0.city_id', $other->id);
});

it('warns when pricing is created for a vehicle class that is turned off', function () {
    $this->comfort->update(['is_active' => false]);

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/pricing', pricingPayload([
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->comfort->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('warnings', ["Comfort is turned off, so this pricing won't be used until the vehicle class is turned on."]);
});

it('warns when pricing is created for a class the city does not offer', function () {
    $other = City::factory()->create(['name' => 'Ikeja']);

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/pricing', pricingPayload([
            'city_id' => $other->id,
            'vehicle_class_id' => $this->economy->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('warnings', ["Economy isn't offered in Ikeja, so this pricing won't be used until the class is turned on for that city."]);
});

it('warns when the only pricing for a class starts later', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/pricing', pricingPayload([
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->economy->id,
            'effective_from' => now()->addDay()->toIso8601String(),
        ]))
        ->assertCreated();

    expect($response->json('warnings'))->toHaveCount(1)
        ->and($response->json('warnings.0'))->toContain('Until then Economy has no pricing in Lekki Test');
});

it('returns no warnings for pricing that takes effect now for an offered class', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/admin/pricing', pricingPayload([
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->economy->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('warnings', []);
});

it('warns on the city page when no offered class is priced', function () {
    $this->withToken($this->token)
        ->getJson("/api/v1/admin/cities/{$this->city->id}")
        ->assertOk()
        ->assertJsonPath('warnings', ["Passengers in Lekki Test can't get fare estimates: none of its vehicle classes (Comfort and Economy) has pricing in effect. Add pricing for at least one of them."]);
});

it('warns on the city page about the classes still unpriced', function () {
    PricingConfig::factory()->forCityAndClass($this->city, $this->economy)->create();

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/cities/{$this->city->id}")
        ->assertOk()
        ->assertJsonPath('warnings', ["Lekki Test has no pricing in effect for Comfort. Passengers won't get fares for these classes until pricing is added."]);
});

it('warns when an active city offers no vehicle classes', function () {
    $this->withToken($this->token)
        ->putJson("/api/v1/admin/cities/{$this->city->id}/vehicle-classes", [
            'vehicle_classes' => [
                ['vehicle_class_id' => $this->economy->id, 'is_active' => false],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('warnings', ["Lekki Test is active but doesn't offer any vehicle classes, so passengers can't book rides there."]);
});

it('counts unpriced classes per city in the city list', function () {
    PricingConfig::factory()->forCityAndClass($this->city, $this->economy)->create();

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/cities')
        ->assertOk();

    $city = collect($response->json('cities'))->firstWhere('id', $this->city->id);

    expect($city['unpriced_vehicle_classes_count'])->toBe(1);
});

it('warns when a vehicle class is offered in cities without pricing', function () {
    PricingConfig::factory()->forCityAndClass($this->city, $this->comfort)->create();

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/vehicle-classes/{$this->economy->id}")
        ->assertOk()
        ->assertJsonPath('warnings', ["Economy has no pricing in effect in Lekki Test. Passengers there won't get fares for it until pricing is added."]);
});

it('warns when turning off a class leaves a city with nothing bookable', function () {
    PricingConfig::factory()->forCityAndClass($this->city, $this->economy)->create();

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/vehicle-classes/{$this->economy->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('warnings', ['Economy is turned off, which leaves Lekki Test with no vehicle class passengers can book.']);
});

it('reports gaps from the coverage command and logs them for active cities', function () {
    Log::spy();
    PricingConfig::factory()->forCityAndClass($this->city, $this->economy)->create();

    $this->artisan('pricing:check-coverage')
        ->expectsOutputToContain('1 city/vehicle class pair(s) have no pricing in effect')
        ->assertFailed();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => str_contains($message, 'no pricing in effect')
            && $context['gaps'] === ['Lekki Test / Comfort'])
        ->once();
});

it('passes the coverage command when every offered class is priced', function () {
    PricingConfig::factory()->forCityAndClass($this->city, $this->economy)->create();
    PricingConfig::factory()->forCityAndClass($this->city, $this->comfort)->create();

    $this->artisan('pricing:check-coverage')
        ->expectsOutputToContain('Every vehicle class offered in a city has pricing in effect.')
        ->assertSuccessful();
});
