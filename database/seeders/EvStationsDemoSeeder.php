<?php

namespace Database\Seeders;

use App\Console\Commands\SimulateDemoDriversCommand;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\EvStationStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test data for the admin EV station details screen: "Yaba EV Hub", with a
 * driver charging at each of stalls 1 and 2 (stall 2 about to free up),
 * stall 3 out of service, and three drivers waiting in the queue.
 *
 * The five drivers drive electric cars, stay offline and are used only here.
 * Re-running it deletes the station's reservations and the drivers' own, and
 * seeds them afresh, relative to now.
 *
 * php artisan db:seed --class=EvStationsDemoSeeder
 */
class EvStationsDemoSeeder extends Seeder
{
    private const PASSWORD = 'Qwer!234';

    public const STATION_NAME = 'Yaba EV Hub';

    private const FEE = 500.00;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping EvStationsDemoSeeder in production.');

            return;
        }

        $lagos = City::where('slug', 'lagos')->first();
        $economy = VehicleClass::where('name', 'economy')->first();

        if (! $lagos || ! $economy) {
            $this->command?->error('Lagos and the economy vehicle class are required. Run DemoSeeder first.');

            return;
        }

        $station = DB::transaction(function () use ($lagos, $economy) {
            $drivers = $this->seedDrivers($lagos, $economy);
            $station = $this->seedStation($lagos);

            EvReservation::where('station_id', $station->id)
                ->orWhereIn('driver_id', collect($drivers)->pluck('id'))
                ->delete();

            $this->seedStallsAndQueue($station, $drivers);

            return $station;
        });

        $this->command?->info("EV station demo data ready: GET /admin/ev-stations/{$station->id} ({$station->name}).");
    }

    /**
     * @return array<string, User>
     */
    private function seedDrivers(City $lagos, VehicleClass $economy): array
    {
        $definitions = [
            'ifeoma' => ['Ifeoma', 'Chukwu', '+2348300000041', ['BYD', 'Dolphin', 'White', 'LND-241EV']],
            'dapo' => ['Dapo', 'Adeyemi', '+2348300000042', ['Hyundai', 'Ioniq 5', 'Grey', 'LND-242EV']],
            'zainab' => ['Zainab', 'Lawal', '+2348300000043', ['Nissan', 'Leaf', 'Blue', 'LND-243EV']],
            'obinna' => ['Obinna', 'Okafor', '+2348300000044', ['Kia', 'EV6', 'Black', 'LND-244EV']],
            'sade' => ['Sade', 'Ogunbiyi', '+2348300000045', ['BYD', 'Atto 3', 'Red', 'LND-245EV']],
        ];

        $drivers = [];

        foreach ($definitions as $key => [$firstName, $lastName, $phone, [$make, $model, $colour, $plate]]) {
            $user = User::firstOrCreate(
                ['email' => $key.'.ev@'.SimulateDemoDriversCommand::emailDomain(), 'type' => UserType::Driver],
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
                    'licence_number' => 'DEMOEV'.strtoupper(Str::substr($key, 0, 3)).substr($phone, -4),
                    'status' => DriverStatus::Approved,
                    'approved_at' => now()->subDays(90),
                    'is_online' => false,
                ],
            );

            Vehicle::updateOrCreate(
                ['driver_id' => $driver->id],
                [
                    'make' => $make,
                    'model' => $model,
                    'colour' => $colour,
                    'plate_number' => $plate,
                    'year' => 2023,
                    'fuel_type' => 'electric',
                    'vehicle_class_id' => $economy->id,
                ],
            );

            $drivers[$key] = $user;
        }

        return $drivers;
    }

    private function seedStation(City $lagos): EvChargingStation
    {
        $station = EvChargingStation::updateOrCreate(
            ['name' => self::STATION_NAME, 'city_id' => $lagos->id],
            [
                'lat' => 6.5095,
                'lng' => 3.3711,
                'address' => '14 Herbert Macaulay Way, Yaba, Lagos',
                'total_stalls' => 3,
                'connector_types' => ['CCS2', 'Type2'],
                'power_output_kw' => 60,
                'pricing_per_kwh' => 25000,
                'operating_hours' => ['open' => '06:00', 'close' => '22:00'],
                'status' => EvStationStatus::Active,
            ],
        );

        $station->stalls()->where('stall_number', '>', 3)->delete();

        for ($number = 1; $number <= 3; $number++) {
            $station->stalls()->firstOrCreate(['stall_number' => $number], ['status' => EvStallStatus::Available]);
        }

        return $station;
    }

    /**
     * @param  array<string, User>  $drivers
     */
    private function seedStallsAndQueue(EvChargingStation $station, array $drivers): void
    {
        $stalls = $station->stalls()->orderBy('stall_number')->get()->keyBy('stall_number');

        // Charging for 25 of an expected 50 minutes.
        $stallOneFreeAt = $this->charge($stalls[1], $drivers['ifeoma'], startedMinutesAgo: 25, chargeMinutes: 50);
        // Charging for 52 of an expected 60 minutes: the next stall to free up.
        $stallTwoFreeAt = $this->charge($stalls[2], $drivers['dapo'], startedMinutesAgo: 52, chargeMinutes: 60);

        $stalls[3]->update([
            'status' => EvStallStatus::OutOfService,
            'current_vehicle_driver_id' => null,
            'occupied_since' => null,
            'estimated_departure_at' => null,
            'updated_at' => now()->subHours(3),
        ]);

        // [driver, joined minutes ago, battery %, stall expected to be theirs]
        $queue = [
            ['zainab', 14, 18, $stallTwoFreeAt],
            ['obinna', 9, 24, $stallOneFreeAt],
            ['sade', 3, 31, $stallTwoFreeAt->copy()->addMinutes(45)],
        ];

        foreach ($queue as $index => [$key, $joinedMinutesAgo, $battery, $availableAt]) {
            $joinedAt = now()->subMinutes($joinedMinutesAgo);

            EvReservation::forceCreate([
                'station_id' => $station->id,
                'driver_id' => $drivers[$key]->id,
                'status' => EvReservationStatus::Queued,
                'type' => 'charging',
                'battery_level' => $battery,
                'queue_position' => $index + 1,
                'estimated_available_at' => $availableAt,
                'fee_amount' => self::FEE,
                'fee_waived' => false,
                'reserved_at' => $joinedAt,
                'created_at' => $joinedAt,
                'updated_at' => $joinedAt,
            ]);
        }
    }

    /**
     * Puts the driver on the stall with an active reservation, as arriving and
     * plugging in does, and returns when the stall should be free.
     */
    private function charge(EvChargingStall $stall, User $driver, int $startedMinutesAgo, int $chargeMinutes): Carbon
    {
        $startedAt = now()->subMinutes($startedMinutesAgo);
        $reservedAt = $startedAt->copy()->subMinutes(10);
        $freeAt = $startedAt->copy()->addMinutes($chargeMinutes);

        EvReservation::forceCreate([
            'stall_id' => $stall->id,
            'station_id' => $stall->station_id,
            'driver_id' => $driver->id,
            'status' => EvReservationStatus::Active,
            'type' => 'charging',
            'fee_amount' => self::FEE,
            'fee_waived' => false,
            'fee_charged_at' => $reservedAt,
            'estimated_charge_minutes' => $chargeMinutes,
            'reserved_at' => $reservedAt,
            'activated_at' => $startedAt,
            'created_at' => $reservedAt,
            'updated_at' => $startedAt,
        ]);

        $stall->update([
            'status' => EvStallStatus::Occupied,
            'current_vehicle_driver_id' => $driver->id,
            'occupied_since' => $startedAt,
            'estimated_departure_at' => $freeAt,
            'updated_at' => $startedAt,
        ]);

        return $freeAt;
    }
}
