<?php

use App\Enums\RideStatus;
use App\Models\City;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;
use Database\Seeders\DriverEarningsDemoSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

it('replaces earnings demo rides with complete trips across more than 100 routes', function () {
    $city = City::factory()->create();
    $vehicleClass = VehicleClass::factory()->create();
    $driver = Driver::factory()->create(['city_id' => $city->id]);
    $passenger = User::factory()->passenger()->create();
    $previousRide = Ride::factory()->create([
        'driver_id' => $driver->user_id,
        'city_id' => $city->id,
        'vehicle_class_id' => $vehicleClass->id,
        'pricing_snapshot' => ['demo_seed' => 'driver_earnings_six_month_demo'],
    ]);
    Config::set('services.demo_earnings_driver_user_id', $driver->user_id);

    app(DriverEarningsDemoSeeder::class)->run();

    $rides = Ride::query()
        ->whereJsonContains('pricing_snapshot->demo_seed', 'driver_earnings_six_month_demo')
        ->get();
    $routes = $rides->map(fn (Ride $ride): string => implode('|', [
        $ride->pickup_address,
        $ride->destination_address,
    ]))->unique();

    $this->assertDatabaseMissing('rides', ['id' => $previousRide->id]);
    $this->assertDatabaseCount('payments', 1000);
    $this->assertDatabaseCount('ride_state_transitions', 4000);
    expect($rides)->toHaveCount(1000);
    expect($routes)->toHaveCount(210);
    $ratings = DB::table('ratings')->whereIn('ride_id', $rides->pluck('id'))->get();
    expect($ratings)->toHaveCount(1000);
    expect($ratings->every(fn (object $rating): bool => $rating->rated_user_id === $driver->user_id
        && $rating->score >= 3
        && $rating->score <= 5
    ))->toBeTrue();
    expect($rides->every(fn (Ride $ride): bool => $ride->status === RideStatus::Completed
        && $ride->passenger_id !== null
        && $ride->vehicle_class_id === $vehicleClass->id
        && $ride->pickup_address !== $ride->destination_address
        && data_get($ride->pricing_snapshot, 'distance_km') > 0
        && data_get($ride->pricing_snapshot, 'duration_minutes') > 0
        && $ride->started_at !== null
        && $ride->completed_at !== null
    ))->toBeTrue();
});
