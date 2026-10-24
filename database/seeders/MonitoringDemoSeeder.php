<?php

namespace Database\Seeders;

use App\Console\Commands\SimulateDemoDriversCommand;
use App\Enums\DriverStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Enums\SosIncidentStatus;
use App\Enums\SosTriggerType;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\GamificationProfile;
use App\Models\Rating;
use App\Models\Ride;
use App\Models\RideStateTransition;
use App\Models\SosIncident;
use App\Models\TripCarbonScore;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test data for the admin Live Monitoring, Manual Assignment and Gamification
 * screens: pending rides with available drivers near their pickups, active
 * rides at every stage (one with an open SOS), and passengers in each tier
 * across several cities.
 *
 * Re-running it cancels the previous demo rides and seeds fresh ones, so
 * rides used up while testing manual assignment come back. Drivers keep
 * reporting their location through `php artisan demo:simulate-drivers`, which
 * the scheduler runs every minute outside production.
 *
 * php artisan db:seed --class=MonitoringDemoSeeder
 */
class MonitoringDemoSeeder extends Seeder
{
    private const PASSWORD = 'Qwer!234';

    /**
     * @var array<string, array{0: float, 1: float, 2: string}>
     */
    private const PLACES = [
        'ikeja_mall' => [6.6143785, 3.3577680, 'Ikeja City Mall, Alausa, Ikeja'],
        'unilag' => [6.5158000, 3.3897000, 'University of Lagos, Akoka, Yaba'],
        'admiralty' => [6.4433000, 3.4720000, 'Admiralty Way, Lekki Phase 1'],
        'maryland' => [6.5671147, 3.3673102, 'Maryland Mall, Maryland'],
        'stadium' => [6.4971425, 3.3649880, 'National Stadium, Surulere'],
        'gbagada' => [6.5523659, 3.3867740, 'Gbagada General Hospital, Gbagada'],
        'bourdillon' => [6.4474000, 3.4364000, 'Bourdillon Road, Ikoyi'],
        'marina' => [6.4461596, 3.3950293, 'Marina, Lagos Island'],
        'landmark' => [6.4231205, 3.4453480, 'Landmark Centre, Victoria Island'],
        'airport' => [6.5774000, 3.3210000, 'Murtala Muhammed Airport, Ikeja'],
        'ikeja_gra' => [6.5833000, 3.3533000, 'Joel Ogunnaike Street, Ikeja GRA'],
        'ajah' => [6.4698000, 3.5852000, 'Ajah Roundabout, Ajah'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping MonitoringDemoSeeder in production.');

            return;
        }

        $lagos = City::where('slug', 'lagos')->first();
        $economy = VehicleClass::where('name', 'economy')->first();
        $comfort = VehicleClass::where('name', 'comfort')->first();

        if (! $lagos || ! $economy || ! $comfort) {
            $this->command?->error('Lagos and the economy and comfort vehicle classes are required. Run DemoSeeder first.');

            return;
        }

        $this->resetPreviousRides();

        $drivers = $this->seedDrivers($lagos, $economy, $comfort);
        $passengers = $this->seedPassengers();

        $this->seedPendingRides($lagos, $economy, $comfort, $passengers);
        $this->seedActiveRides($lagos, $economy, $comfort, $passengers, $drivers);
        $this->seedGamification($drivers['ola']);
        $this->seedDriverRatings($lagos, $drivers, $passengers);
        $this->placeIdleDrivers($drivers);

        $this->command?->info('Monitoring demo data ready. Demo drivers report their location every minute via the scheduler (or run `php artisan demo:simulate-drivers --loop`).');
    }

    private function email(string $name): string
    {
        return $name.'@'.SimulateDemoDriversCommand::emailDomain();
    }

    private function resetPreviousRides(): void
    {
        $demoUserIds = User::where('email', 'like', '%@'.SimulateDemoDriversCommand::emailDomain())->pluck('id');

        $staleRides = Ride::where(fn ($q) => $q->whereIn('passenger_id', $demoUserIds)->orWhereIn('driver_id', $demoUserIds))
            ->whereIn('status', [...RideStatus::activeStatuses(), RideStatus::NoDriverFound])
            ->pluck('id');

        SosIncident::whereIn('ride_id', $staleRides)->active()->update([
            'status' => SosIncidentStatus::Resolved,
            'operator_notes' => 'Demo data reset.',
            'resolved_at' => now(),
        ]);

        Ride::whereIn('id', $staleRides)->update([
            'status' => RideStatus::Cancelled,
            'cancellation_reason' => 'Demo data reset.',
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, Driver>
     */
    private function seedDrivers(City $lagos, VehicleClass $economy, VehicleClass $comfort): array
    {
        $definitions = [
            // On active rides
            'ola' => ['Ola', 'Adewale', '+2348300000001', $economy, ['Toyota', 'Corolla', 'Silver', 'LND-201AA']],
            'sade' => ['Sade', 'Ogunbiyi', '+2348300000002', $economy, ['Honda', 'Civic', 'Grey', 'LND-202AB']],
            'ibrahim' => ['Ibrahim', 'Lawal', '+2348300000003', $comfort, ['Toyota', 'Camry', 'Black', 'LND-203AC']],
            'chuka' => ['Chuka', 'Eze', '+2348300000004', $economy, ['Hyundai', 'Elantra', 'White', 'LND-204AD']],
            'yemi' => ['Yemi', 'Alade', '+2348300000005', $economy, ['Kia', 'Rio', 'Red', 'LND-205AE']],
            // Idle, near the pending pickups
            'tobi' => ['Tobi', 'Ajayi', '+2348300000006', $economy, ['Toyota', 'Yaris', 'Blue', 'LND-206AF']],
            'efe' => ['Efe', 'Okoro', '+2348300000007', $economy, ['Nissan', 'Almera', 'White', 'LND-207AG']],
            'hauwa' => ['Hauwa', 'Musa', '+2348300000008', $economy, ['Toyota', 'Corolla', 'Black', 'LND-208AH']],
            'dayo' => ['Dayo', 'Fashola', '+2348300000009', $comfort, ['Honda', 'Accord', 'Silver', 'LND-209AI']],
            'nneka' => ['Nneka', 'Obi', '+2348300000010', $comfort, ['Lexus', 'ES 350', 'Grey', 'LND-210AJ']],
        ];

        $drivers = [];

        foreach ($definitions as $key => [$firstName, $lastName, $phone, $class, [$make, $model, $colour, $plate]]) {
            $user = User::firstOrCreate(
                ['email' => $this->email("driver.{$key}"), 'type' => UserType::Driver],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'password' => Hash::make(self::PASSWORD),
                    'phone_verified_at' => now(),
                    'is_active' => true,
                ],
            );

            $driver = Driver::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'city_id' => $lagos->id,
                    'licence_number' => 'DEMO'.strtoupper(Str::substr($key, 0, 3)).substr($phone, -4),
                    'status' => DriverStatus::Approved,
                    'approved_at' => now()->subDays(30),
                    'is_online' => true,
                ],
            );

            Vehicle::updateOrCreate(
                ['driver_id' => $driver->id],
                [
                    'make' => $make,
                    'model' => $model,
                    'colour' => $colour,
                    'plate_number' => $plate,
                    'year' => 2021,
                    'fuel_type' => 'petrol',
                    'vehicle_class_id' => $class->id,
                ],
            );

            $drivers[$key] = $driver;
        }

        return $drivers;
    }

    /**
     * @return array<string, User>
     */
    private function seedPassengers(): array
    {
        $definitions = [
            'amaka' => ['Amaka', 'Nwankwo', '+2348400000001'],
            'femi' => ['Femi', 'Oyelaran', '+2348400000002'],
            'halima' => ['Halima', 'Sani', '+2348400000003'],
            'uche' => ['Uche', 'Okeke', '+2348400000004'],
            'kunle' => ['Kunle', 'Bello', '+2348400000005'],
            'precious' => ['Precious', 'Etim', '+2348400000006'],
            'seyi' => ['Seyi', 'Adeniran', '+2348400000007'],
            'grace' => ['Grace', 'Udoh', '+2348400000008'],
        ];

        return collect($definitions)
            ->map(fn (array $data, string $key) => $this->passenger($key, ...$data))
            ->all();
    }

    private function passenger(string $key, string $firstName, string $lastName, string $phone): User
    {
        return User::firstOrCreate(
            ['email' => $this->email("passenger.{$key}"), 'type' => UserType::Passenger],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'password' => Hash::make(self::PASSWORD),
                'phone_verified_at' => now(),
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  array<string, User>  $passengers
     */
    private function seedPendingRides(City $lagos, VehicleClass $economy, VehicleClass $comfort, array $passengers): void
    {
        $this->createRide($lagos, $economy, $passengers['amaka'], null, 'ikeja_mall', 'landmark', RideStatus::Searching, [
            RideStatus::Searching->value => now()->subMinutes(2),
        ]);

        $this->createRide($lagos, $economy, $passengers['femi'], null, 'unilag', 'bourdillon', RideStatus::NoDriverFound, [
            RideStatus::Searching->value => now()->subMinutes(7),
            RideStatus::NoDriverFound->value => now()->subMinutes(4),
        ]);

        $this->createRide($lagos, $comfort, $passengers['halima'], null, 'admiralty', 'airport', RideStatus::Searching, [
            RideStatus::Searching->value => now()->subMinute(),
        ]);
    }

    /**
     * @param  array<string, User>  $passengers
     * @param  array<string, Driver>  $drivers
     */
    private function seedActiveRides(City $lagos, VehicleClass $economy, VehicleClass $comfort, array $passengers, array $drivers): void
    {
        $this->createRide($lagos, $economy, $passengers['uche'], $drivers['ola'], 'maryland', 'marina', RideStatus::Matched, [
            RideStatus::Searching->value => now()->subMinutes(3),
            RideStatus::Matched->value => now()->subMinute(),
        ], driverStartKm: 2.5);

        $this->createRide($lagos, $economy, $passengers['kunle'], $drivers['sade'], 'stadium', 'ikeja_gra', RideStatus::DriverEnRoute, [
            RideStatus::Searching->value => now()->subMinutes(8),
            RideStatus::Matched->value => now()->subMinutes(6),
            RideStatus::DriverEnRoute->value => now()->subMinutes(6),
        ], driverStartKm: 1.8);

        $this->createRide($lagos, $comfort, $passengers['precious'], $drivers['ibrahim'], 'gbagada', 'landmark', RideStatus::DriverArrived, [
            RideStatus::Searching->value => now()->subMinutes(14),
            RideStatus::Matched->value => now()->subMinutes(12),
            RideStatus::DriverEnRoute->value => now()->subMinutes(12),
            RideStatus::DriverArrived->value => now()->subMinutes(2),
        ], driverStartKm: 0);

        $sosRide = $this->createRide($lagos, $economy, $passengers['seyi'], $drivers['chuka'], 'bourdillon', 'airport', RideStatus::InProgress, [
            RideStatus::Searching->value => now()->subMinutes(27),
            RideStatus::Matched->value => now()->subMinutes(25),
            RideStatus::DriverEnRoute->value => now()->subMinutes(25),
            RideStatus::DriverArrived->value => now()->subMinutes(16),
            RideStatus::InProgress->value => now()->subMinutes(14),
        ], tripProgress: 0.4);

        $this->createRide($lagos, $economy, $passengers['grace'], $drivers['yemi'], 'marina', 'ajah', RideStatus::InProgress, [
            RideStatus::Searching->value => now()->subMinutes(20),
            RideStatus::Matched->value => now()->subMinutes(19),
            RideStatus::DriverEnRoute->value => now()->subMinutes(19),
            RideStatus::DriverArrived->value => now()->subMinutes(12),
            RideStatus::InProgress->value => now()->subMinutes(10),
        ], tripProgress: 0.3);

        $location = app(DriverLocationService::class)->getDriverLocation((string) $drivers['chuka']->id);

        SosIncident::forceCreate([
            'ride_id' => $sosRide->id,
            'triggered_by_user_id' => $sosRide->passenger_id,
            'trigger_type' => SosTriggerType::Passenger,
            'status' => SosIncidentStatus::Triggered,
            'gps_lat' => $location['lat'] ?? $sosRide->pickup_lat,
            'gps_lng' => $location['lng'] ?? $sosRide->pickup_lng,
            'vehicle_details' => ['plate_number' => 'LND-204AD', 'make' => 'Hyundai', 'model' => 'Elantra', 'color' => 'White'],
            'created_at' => now()->subMinutes(3),
        ]);
    }

    /**
     * @param  array<string, Carbon>  $timeline  Status reached => when, in order
     */
    private function createRide(
        City $city,
        VehicleClass $class,
        User $passenger,
        ?Driver $driver,
        string $from,
        string $to,
        RideStatus $status,
        array $timeline,
        ?float $driverStartKm = null,
        ?float $tripProgress = null,
    ): Ride {
        [$pickupLat, $pickupLng, $pickupAddress] = self::PLACES[$from];
        [$destinationLat, $destinationLng, $destinationAddress] = self::PLACES[$to];

        $requestedAt = reset($timeline)->copy()->subSeconds(5);

        $ride = Ride::forceCreate([
            'city_id' => $city->id,
            'vehicle_class_id' => $class->id,
            'passenger_id' => $passenger->id,
            'driver_id' => $driver?->user_id,
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'pickup_address' => $pickupAddress,
            'destination_lat' => $destinationLat,
            'destination_lng' => $destinationLng,
            'destination_address' => $destinationAddress,
            'status' => $status,
            'share_token' => Str::random(32),
            'fare_estimate_amount' => $class->name === 'comfort' ? 6200 : 4300,
            'fare_currency' => 'NGN',
            'pricing_snapshot' => [
                'base_fare' => '600.00',
                'per_km_rate' => '250.00',
                'per_minute_rate' => '40.00',
                'minimum_fare' => '1500.00',
                'free_waiting_minutes' => 5,
                'captured_at' => $requestedAt->toIso8601String(),
            ],
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Pending,
            'matched_at' => $timeline[RideStatus::Matched->value] ?? null,
            'started_at' => $timeline[RideStatus::InProgress->value] ?? null,
            'created_at' => $requestedAt,
            'updated_at' => end($timeline),
        ]);

        $previous = RideStatus::Requested;
        $this->recordTransition($ride, null, RideStatus::Requested, $requestedAt, $passenger->id, 'passenger');

        foreach ($timeline as $statusValue => $at) {
            $next = RideStatus::from($statusValue);
            [$actorId, $actorType] = in_array($next, [RideStatus::Searching, RideStatus::NoDriverFound], true)
                ? [null, 'system']
                : [$driver?->user_id, 'driver'];

            $this->recordTransition($ride, $previous, $next, $at, $actorId, $actorType);
            $previous = $next;
        }

        if ($driver) {
            [$lat, $lng] = match (true) {
                $tripProgress !== null => [
                    $pickupLat + ($destinationLat - $pickupLat) * $tripProgress,
                    $pickupLng + ($destinationLng - $pickupLng) * $tripProgress,
                ],
                default => $this->offset($pickupLat, $pickupLng, $driverStartKm ?? 0),
            };

            $this->reportLocation($driver, $lat, $lng);
        }

        return $ride;
    }

    private function recordTransition(Ride $ride, ?RideStatus $from, RideStatus $to, Carbon $at, ?string $actorId, string $actorType): void
    {
        RideStateTransition::create([
            'ride_id' => $ride->id,
            'from_state' => $from,
            'to_state' => $to,
            'triggered_by_type' => $actorType,
            'triggered_by_id' => $actorId,
            'metadata' => ['demo' => true],
            'created_at' => $at,
        ]);
    }

    /**
     * Idle drivers wait at fixed spots near the pending pickups: two economy
     * drivers by Ikeja City Mall, one by UNILAG (the Ikeja pair is too far to
     * show for UNILAG) and two comfort drivers in Lekki, one of them outside
     * the default 5 km radius.
     *
     * @param  array<string, Driver>  $drivers
     */
    private function placeIdleDrivers(array $drivers): void
    {
        $spots = [
            'tobi' => ['ikeja_mall', 0.8],
            'efe' => ['ikeja_mall', 2.3],
            'hauwa' => ['unilag', 1.5],
            'dayo' => ['admiralty', 1.1],
            'nneka' => ['admiralty', 6.5],
        ];

        foreach ($spots as $key => [$place, $km]) {
            [$lat, $lng] = $this->offset(self::PLACES[$place][0], self::PLACES[$place][1], $km);
            $this->reportLocation($drivers[$key], $lat, $lng);
        }
    }

    private function reportLocation(Driver $driver, float $lat, float $lng): void
    {
        Cache::forever(SimulateDemoDriversCommand::homeCacheKey((string) $driver->id), ['lat' => $lat, 'lng' => $lng]);

        app(DriverLocationService::class)->updateLocation((string) $driver->id, $lat, $lng, 0, 0, $driver->user_id);
    }

    /**
     * A point roughly $km north-east of the given one.
     *
     * @return array{0: float, 1: float}
     */
    private function offset(float $lat, float $lng, float $km): array
    {
        $degrees = $km / 111.32 / M_SQRT2;

        return [round($lat + $degrees, 7), round($lng + $degrees / cos(deg2rad($lat)), 7)];
    }

    /**
     * Gives every demo driver two rated, completed trips from earlier in the
     * month so ratings show on the dashboard. Drivers already rated are left
     * alone.
     *
     * @param  array<string, Driver>  $drivers
     * @param  array<string, User>  $passengers
     */
    private function seedDriverRatings(City $lagos, array $drivers, array $passengers): void
    {
        $scores = [[5, 5], [5, 4], [4, 4], [5, 5], [4, 5], [5, 4], [4, 3], [5, 5], [5, 4], [4, 4]];
        $raters = array_values($passengers);

        foreach (array_values($drivers) as $index => $driver) {
            if (Rating::where('rated_user_id', $driver->user_id)->exists()) {
                continue;
            }

            $vehicleClassId = $driver->vehicle()->value('vehicle_class_id');

            foreach ($scores[$index % count($scores)] as $trip => $score) {
                $passenger = $raters[($index + $trip) % count($raters)];
                $completedAt = now()->subDays(3 + $index + $trip * 5)->setTime(9 + $trip * 6, 20);
                [$pickupLat, $pickupLng, $pickupAddress] = self::PLACES[array_keys(self::PLACES)[$index % count(self::PLACES)]];
                [$destinationLat, $destinationLng, $destinationAddress] = self::PLACES[array_keys(self::PLACES)[($index + 5) % count(self::PLACES)]];

                $ride = Ride::forceCreate([
                    'city_id' => $lagos->id,
                    'vehicle_class_id' => $vehicleClassId,
                    'passenger_id' => $passenger->id,
                    'driver_id' => $driver->user_id,
                    'pickup_lat' => $pickupLat,
                    'pickup_lng' => $pickupLng,
                    'pickup_address' => $pickupAddress,
                    'destination_lat' => $destinationLat,
                    'destination_lng' => $destinationLng,
                    'destination_address' => $destinationAddress,
                    'status' => RideStatus::Completed,
                    'share_token' => Str::random(32),
                    'fare_estimate_amount' => 4300,
                    'final_fare_amount' => 4300,
                    'fare_currency' => 'NGN',
                    'payment_method' => PaymentMethod::Cash,
                    'payment_status' => PaymentStatus::Collected,
                    'matched_at' => $completedAt->copy()->subMinutes(30),
                    'started_at' => $completedAt->copy()->subMinutes(24),
                    'completed_at' => $completedAt,
                    'created_at' => $completedAt->copy()->subMinutes(33),
                    'updated_at' => $completedAt,
                ]);

                Rating::create([
                    'ride_id' => $ride->id,
                    'rated_by_user_id' => $passenger->id,
                    'rated_user_id' => $driver->user_id,
                    'score' => $score,
                    'created_at' => $completedAt->copy()->addMinutes(2),
                ]);
            }
        }
    }

    /**
     * Passengers in every tier across four cities, each with completed rides
     * and carbon scores spread over the last three months.
     */
    private function seedGamification(Driver $driver): void
    {
        $definitions = [
            'tolu' => ['Tolu', 'Bakare', '+2348400000011', 'lagos', 5, 16200, 182.40, [6.51, 3.37], [6.45, 3.43]],
            'musa' => ['Musa', 'Ibrahim', '+2348400000012', 'abuja', 4, 6100, 74.10, [9.08, 7.47], [9.03, 7.49]],
            'zainab' => ['Zainab', 'Bello', '+2348400000013', 'abuja', 3, 2450, 31.75, [9.09, 7.50], [9.01, 7.26]],
            'kelechi' => ['Kelechi', 'Obi', '+2348400000014', 'lagos', 3, 3100, 38.20, [6.52, 3.39], [6.43, 3.42]],
            'ebi' => ['Ebi', 'Douglas', '+2348400000015', 'port-harcourt', 2, 820, 11.30, [4.82, 7.05], [4.81, 7.03]],
            'bisi' => ['Bisi', 'Adebayo', '+2348400000016', 'ibadan', 1, 240, 3.60, [7.44, 3.90], [7.39, 3.92]],
        ];

        $vehicleClass = VehicleClass::where('name', 'economy')->first();

        foreach ($definitions as $key => [$firstName, $lastName, $phone, $citySlug, $tier, $points, $carbon, $pickup, $destination]) {
            $city = City::where('slug', $citySlug)->first() ?? City::where('slug', 'lagos')->first();
            $passenger = $this->passenger($key, $firstName, $lastName, $phone);

            GamificationProfile::updateOrCreate(
                ['user_id' => $passenger->id],
                [
                    'total_ranking_points' => $points,
                    'total_carbon_score' => $carbon,
                    'current_tier' => $tier,
                    'tier_upgraded_at' => $tier > 1 ? now()->subDays(20) : null,
                ],
            );

            if (TripCarbonScore::where('user_id', $passenger->id)->exists()) {
                continue;
            }

            foreach ([75, 40, 6] as $index => $daysAgo) {
                $completedAt = now()->subDays($daysAgo)->setTime(8 + $index * 4, 15);
                $distanceKm = 6.5 + $index * 3.2;

                $ride = Ride::forceCreate([
                    'city_id' => $city->id,
                    'vehicle_class_id' => $vehicleClass->id,
                    'passenger_id' => $passenger->id,
                    'driver_id' => $driver->user_id,
                    'pickup_lat' => $pickup[0],
                    'pickup_lng' => $pickup[1],
                    'pickup_address' => "{$city->name} pickup",
                    'destination_lat' => $destination[0],
                    'destination_lng' => $destination[1],
                    'destination_address' => "{$city->name} drop-off",
                    'status' => RideStatus::Completed,
                    'share_token' => Str::random(32),
                    'fare_estimate_amount' => 3500,
                    'final_fare_amount' => 3500,
                    'fare_currency' => 'NGN',
                    'payment_method' => PaymentMethod::Cash,
                    'payment_status' => PaymentStatus::Collected,
                    'matched_at' => $completedAt->copy()->subMinutes(32),
                    'started_at' => $completedAt->copy()->subMinutes(25),
                    'completed_at' => $completedAt,
                    'created_at' => $completedAt->copy()->subMinutes(35),
                    'updated_at' => $completedAt,
                ]);

                $baseline = round($distanceKm * 0.12, 4);
                $vehicle = round($distanceKm * 0.075, 4);
                $basePoints = max(5, (int) round(($baseline - $vehicle) * 10 * $tier));

                TripCarbonScore::create([
                    'ride_id' => $ride->id,
                    'user_id' => $passenger->id,
                    'distance_km' => $distanceKm,
                    'baseline_emission' => $baseline,
                    'vehicle_emission' => $vehicle,
                    'co2_saved' => round($baseline - $vehicle, 4),
                    'base_points' => $basePoints,
                    'multiplier_applied' => 1.00,
                    'final_points' => $basePoints,
                    'created_at' => $completedAt,
                ]);
            }
        }
    }
}
