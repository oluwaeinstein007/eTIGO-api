<?php

use App\Models\City;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\VehicleClass;
use Database\Seeders\AdminSeeder;
use Database\Seeders\CityActivityDemoSeeder;
use Database\Seeders\CitySeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ScheduledRidesDemoSeeder;

it('books classes a city offers when it was set up differently from the demo data', function () {
    $this->seed([ReferenceDataSeeder::class, AdminSeeder::class, CitySeeder::class, PricingConfigSeeder::class]);

    // As on staging: Abuja does not offer Economy.
    $abuja = City::where('slug', 'abuja')->first();
    $economy = VehicleClass::where('name', 'economy')->first();
    $abuja->vehicleClasses()->updateExistingPivot($economy->id, ['is_active' => false]);

    $this->seed([DemoSeeder::class, CityActivityDemoSeeder::class, ScheduledRidesDemoSeeder::class]);

    expect(ScheduledRide::count())->toBeGreaterThanOrEqual(5)
        ->and(Ride::where('city_id', $abuja->id)->where('vehicle_class_id', $economy->id)->whereHas('scheduledRide')->count())->toBe(0);
});
