<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\HaversineMapsGateway;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DriverEarningsDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const string SEED_MARKER = 'driver_earnings_six_month_demo';

    private const int TARGET_RIDE_COUNT = 1000;

    /** @var array<array{latitude: float, longitude: float, address: string}> */
    private const array LAGOS_PLACES = [
        ['latitude' => 6.6143785, 'longitude' => 3.3577680, 'address' => 'Ikeja City Mall, Ikeja, Lagos'],
        ['latitude' => 6.6156242, 'longitude' => 3.3607369, 'address' => 'Lagos State Secretariat, Alausa, Lagos'],
        ['latitude' => 6.5120272, 'longitude' => 3.3935314, 'address' => 'University of Lagos, Akoka, Lagos'],
        ['latitude' => 6.4971425, 'longitude' => 3.3649880, 'address' => 'Lagos National Stadium, Surulere, Lagos'],
        ['latitude' => 6.5671147, 'longitude' => 3.3673102, 'address' => 'Maryland Mall, Lagos'],
        ['latitude' => 6.5523659, 'longitude' => 3.3867740, 'address' => 'Gbagada General Hospital, Lagos'],
        ['latitude' => 6.4461596, 'longitude' => 3.3950293, 'address' => 'Marina, Lagos Island'],
        ['latitude' => 6.4231205, 'longitude' => 3.4453480, 'address' => 'Landmark Centre, Victoria Island, Lagos'],
        ['latitude' => 6.5942452, 'longitude' => 3.3402182, 'address' => 'Computer Village, Ikeja, Lagos'],
        ['latitude' => 6.4335621, 'longitude' => 3.4491265, 'address' => 'British International School, Lekki Phase I, Lagos'],
        ['latitude' => 6.4694716, 'longitude' => 3.5623861, 'address' => 'Ajah, Lagos'],
        ['latitude' => 6.6191233, 'longitude' => 3.5041271, 'address' => 'Ikorodu, Lagos'],
        ['latitude' => 6.4682727, 'longitude' => 3.2839374, 'address' => 'Festac Town, Lagos'],
        ['latitude' => 6.4451870, 'longitude' => 3.3683732, 'address' => 'Apapa, Lagos'],
        ['latitude' => 6.5870705, 'longitude' => 3.3791462, 'address' => 'Ojota, Lagos'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Skipping earnings demo data in production.');

            return;
        }

        $driverUserId = config('services.demo_earnings_driver_user_id');
        if (! is_string($driverUserId) || $driverUserId === '') {
            $this->command?->error('Set DEMO_EARNINGS_DRIVER_USER_ID to the target driver user id.');

            return;
        }

        $driverUser = User::query()->whereKey($driverUserId)->where('type', UserType::Driver)->first();
        if (! $driverUser) {
            $this->command?->error('The configured earnings demo driver was not found.');

            return;
        }

        $existingRideCount = Ride::query()
            ->whereJsonContains('pricing_snapshot->demo_seed', self::SEED_MARKER)
            ->count();

        $driver = Driver::query()->where('user_id', $driverUser->id)->first();
        $passengers = User::query()->where('type', UserType::Passenger)->get();
        $city = $driver?->city ?? City::query()->where('is_active', true)->first();
        $vehicleClassId = $driver?->vehicle?->vehicle_class_id
            ?? VehicleClass::query()->where('is_active', true)->value('id');

        if (! $city || ! $vehicleClassId || $passengers->isEmpty()) {
            $this->command?->error('Seed a city, vehicle class, and passenger account before earnings demo data.');

            return;
        }

        $places = self::LAGOS_PLACES;
        $placeCount = count($places);
        $mapGateway = new HaversineMapsGateway;
        $rideCount = DB::transaction(function () use ($driverUser, $passengers, $city, $vehicleClassId, $places, $placeCount, $mapGateway): int {
            Ride::query()
                ->whereJsonContains('pricing_snapshot->demo_seed', self::SEED_MARKER)
                ->delete();

            $today = Carbon::today();
            $month = $today->copy()->subMonthsNoOverflow(5)->startOfMonth();
            $totalDays = $month->diffInDays($today) + 1;
            $passengerCount = $passengers->count();

            for ($sequence = 0; $sequence < self::TARGET_RIDE_COUNT; $sequence++) {
                $dayOffset = intdiv($sequence * $totalDays, self::TARGET_RIDE_COUNT);
                $completedAt = $month->copy()->addDays($dayOffset)
                    ->setTime(7 + (($sequence * 7) % 14), ($sequence * 13) % 60);
                $pickupIndex = $sequence % $placeCount;
                $destinationOffset = intdiv($sequence, $placeCount) % ($placeCount - 1) + 1;
                $pickup = $places[$pickupIndex];
                $destination = $places[($pickupIndex + $destinationOffset) % $placeCount];
                $route = $mapGateway->getDistanceAndDuration(
                    $pickup['latitude'],
                    $pickup['longitude'],
                    $destination['latitude'],
                    $destination['longitude'],
                );

                $this->createCompletedRide(
                    driver: $driverUser,
                    passenger: $passengers[$sequence % $passengerCount],
                    city: $city,
                    vehicleClassId: (string) $vehicleClassId,
                    completedAt: $completedAt,
                    sequence: $sequence,
                    pickup: $pickup,
                    destination: $destination,
                    distanceKm: $route['distance_km'],
                    durationMinutes: $route['duration_minutes'],
                );
            }

            return self::TARGET_RIDE_COUNT;
        });

        $this->command?->info("Replaced {$existingRideCount} prior earnings demo rides with {$rideCount} complete rides across more than 100 Lagos routes for {$driverUser->first_name}.");
    }

    private function createCompletedRide(
        User $driver,
        User $passenger,
        City $city,
        string $vehicleClassId,
        Carbon $completedAt,
        int $sequence,
        array $pickup,
        array $destination,
        float $distanceKm,
        float $durationMinutes,
    ): void {
        $fare = max(1500, round(600 + ($distanceKm * 250) + ($durationMinutes * 40), 2));
        $startedAt = $completedAt->copy()->subMinutes(27 + ($sequence % 24));
        $createdAt = $startedAt->copy()->subMinutes(11);
        $ride = Ride::query()->create([
            'city_id' => $city->id,
            'vehicle_class_id' => $vehicleClassId,
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->id,
            'pickup_lat' => $pickup['latitude'],
            'pickup_lng' => $pickup['longitude'],
            'pickup_address' => $pickup['address'],
            'destination_lat' => $destination['latitude'],
            'destination_lng' => $destination['longitude'],
            'destination_address' => $destination['address'],
            'status' => RideStatus::Completed,
            'pin_code' => '1234',
            'share_token' => Str::random(40),
            'fare_estimate_amount' => $fare,
            'final_fare_amount' => $fare,
            'fare_currency' => 'NGN',
            'pricing_snapshot' => [
                'base_fare' => 600,
                'per_km_rate' => 250,
                'per_minute_rate' => 40,
                'minimum_fare' => 1500,
                'distance_km' => $distanceKm,
                'duration_minutes' => $durationMinutes,
                'demo_seed' => self::SEED_MARKER,
            ],
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Settled,
            'matched_at' => $createdAt->copy()->addMinutes(3),
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'created_at' => $createdAt,
            'updated_at' => $completedAt,
        ]);

        foreach ([
            [null, RideStatus::Requested->value, $createdAt],
            [RideStatus::Requested->value, RideStatus::Matched->value, $createdAt->copy()->addMinutes(3)],
            [RideStatus::Matched->value, RideStatus::InProgress->value, $startedAt],
            [RideStatus::InProgress->value, RideStatus::Completed->value, $completedAt],
        ] as [$fromState, $toState, $occurredAt]) {
            DB::table('ride_state_transitions')->insert([
                'id' => (string) Str::uuid(),
                'ride_id' => $ride->id,
                'from_state' => $fromState,
                'to_state' => $toState,
                'triggered_by_type' => User::class,
                'triggered_by_id' => $driver->id,
                'metadata' => json_encode(['source' => self::SEED_MARKER]),
                'created_at' => $occurredAt,
            ]);
        }

        $payment = new Payment([
            'ride_id' => $ride->id,
            'amount' => $fare,
            'currency' => 'NGN',
            'method' => PaymentMethod::Card->value,
            'gateway_transaction_id' => 'DEMO_EARNINGS_'.$ride->id,
            'tip_amount' => 0,
            'status' => 'successful',
        ]);
        $payment->created_at = $completedAt;
        $payment->updated_at = $completedAt;
        $payment->save();

        $scores = [5, 5, 4, 5, 4, 5, 5, 3, 5, 4];
        $comments = [
            'Smooth and comfortable ride.',
            'Driver was polite and on time.',
            'Clean vehicle and a good trip.',
            'Great experience.',
            null,
            null,
        ];

        DB::table('ratings')->insert([
            'id' => (string) Str::uuid(),
            'ride_id' => $ride->id,
            'rated_by_user_id' => $passenger->id,
            'rated_user_id' => $driver->id,
            'score' => $scores[$sequence % count($scores)],
            'comment' => $comments[$sequence % count($comments)],
            'created_at' => $completedAt->copy()->addMinutes(5),
        ]);
    }
}
