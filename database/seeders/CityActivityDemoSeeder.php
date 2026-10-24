<?php

namespace Database\Seeders;

use App\Enums\CancellationReason;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\FuelType;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Enums\VehicleOwnershipType;
use App\Models\BankAccount;
use App\Models\City;
use App\Models\CityWaitlistEntry;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\KycVerification;
use App\Models\PromoCode;
use App\Models\SavedPlace;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\WalletPaymentService;
use Database\Seeders\Concerns\SeedsDemoActivity;
use Database\Seeders\Data\NigerianCities;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Activity in every demo city (Data\NigerianCities), so the apps and admin
 * screens show each city as it would look after launch:
 *
 * - Live cities: local drivers (approved, plus one awaiting review) with
 *   their cars, documents, KYC and bank accounts; passengers with saved
 *   places, cards and wallets topped up through the ledger; and a few weeks
 *   of trips (cash, card and wallet, some cancelled or unmatched), priced
 *   from the city's fares, settled through the real services, rated and
 *   scored for carbon points.
 * - A city an admin has since paused: the same, up to the day it was
 *   paused, and a waitlist. An onboarding one: driver applicants and a
 *   waitlist.
 * - City promo codes for Ilorin, Enugu, Port Harcourt and Benin City.
 *
 * Drivers use @ops-demo.etigo.com, so matching never offers them real rides,
 * and stay offline. Everyone's password is Qwer!234. A city already seeded is
 * skipped, so it is safe to run again.
 *
 * php artisan db:seed --class=CityActivityDemoSeeder
 */
class CityActivityDemoSeeder extends Seeder
{
    use SeedsDemoActivity;

    /**
     * Drivers, passengers and trips per fare tier.
     */
    private const SIZE = [
        'A' => [8, 8, 32],
        'B' => [5, 5, 18],
        'C' => [4, 4, 10],
    ];

    /**
     * The class each driver drives, in order, kept to what the city offers.
     */
    private const CLASS_ORDER = ['economy', 'economy', 'comfort', 'economy', 'premium', 'comfort', 'suv', 'economy'];

    private string $password;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping CityActivityDemoSeeder in production.');

            return;
        }

        $this->password = Hash::make('Qwer!234');

        // Same names, cars and trips on every fresh database.
        mt_srand(20261024);

        foreach (NigerianCities::all() as $index => $data) {
            $city = City::where('slug', $data['slug'])->first();

            if (! $city || Driver::where('city_id', $city->id)->whereHas('user', fn ($q) => $q->where('email', 'like', '%.'.$data['slug'].'@ops-demo.etigo.com'))->exists()) {
                continue;
            }

            if ($city->launched_at !== null) {
                $this->seedLaunchedCity($city, $data, $index);
            } else {
                $this->seedUpcomingCity($city, $data, $index);
            }
        }

        $this->seedCityPromos();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedLaunchedCity(City $city, array $data, int $index): void
    {
        [$driverCount, $passengerCount, $tripCount] = self::SIZE[$data['tier']];
        $offered = $city->vehicleClasses()->wherePivot('is_active', true)->get()->keyBy('name');
        $classOrder = array_values(array_filter(self::CLASS_ORDER, fn (string $name) => $offered->has($name)));

        $drivers = [];
        for ($n = 0; $n < $driverCount; $n++) {
            $status = $n === $driverCount - 1 ? DriverStatus::PendingReview : DriverStatus::Approved;
            $drivers[] = $this->driver($city, $data, $index, $n, $offered[$classOrder[$n % count($classOrder)]], $status);
        }

        $passengers = [];
        for ($n = 0; $n < $passengerCount; $n++) {
            $passengers[] = $this->passenger($city, $data, $index, $n);
        }

        $approved = array_values(array_filter($drivers, fn (array $d) => $d['driver']->status === DriverStatus::Approved));

        // Trips run from launch (or three weeks ago) to two hours ago, or to
        // the day the city was paused.
        $from = Carbon::parse($city->launched_at)->max(now()->subDays(21));
        $until = $city->paused_at ? Carbon::parse($city->paused_at)->subHours(6) : now()->subHours(2);

        for ($t = 0; $t < $tripCount; $t++) {
            $driver = $approved[$t % count($approved)];
            $passenger = $passengers[mt_rand(0, count($passengers) - 1)];
            [$pickup, $destination] = $this->landmarkPair($data['landmarks']);

            $this->trip([
                'city' => $city,
                'tier' => $data['tier'],
                'class' => $driver['class'],
                'passenger' => $passenger,
                'driver' => $driver['user'],
                'from' => $pickup,
                'to' => $destination,
                'requested_at' => $this->tripTime($from, $until),
                'payment' => $this->paymentFor($passenger),
                'rider' => $data['slug'] === 'lagos' && $t % 11 === 5 ? $this->thirdPartyRider($t) : null,
                'tip_kobo' => mt_rand(1, 5) === 1 ? mt_rand(2, 10) * 10000 : 0,
                'rating' => mt_rand(1, 5) > 1 ? $this->passengerRating() : null,
                'driver_rating' => mt_rand(1, 3) > 1 ? (mt_rand(1, 10) > 1 ? 5 : 4) : null,
                ...$this->outcome(),
            ]);
        }

        // Riders in a paused city ask to be told when it is back.
        if ($city->paused_at) {
            foreach (array_slice($passengers, 0, 3) as $passenger) {
                $this->joinWaitlist($city, $passenger, Carbon::parse($city->paused_at)->addDays(mt_rand(0, 3)));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedUpcomingCity(City $city, array $data, int $index): void
    {
        $economy = VehicleClass::where('name', 'economy')->first();
        $comfort = VehicleClass::where('name', 'comfort')->first() ?? $economy;

        $this->driver($city, $data, $index, 0, $economy, DriverStatus::PendingReview);
        $this->driver($city, $data, $index, 1, $comfort, DriverStatus::Onboarding);
        $this->driver($city, $data, $index, 2, $economy, DriverStatus::PendingReview);

        $waiting = $data['launch_date'] ? 6 : 3;
        for ($n = 0; $n < $waiting; $n++) {
            $passenger = $this->passenger($city, $data, $index, $n, withActivity: false);

            $this->joinWaitlist($city, $passenger, now()->subDays(mt_rand(1, 20))->subMinutes(mt_rand(0, 600)));
        }
    }

    private function joinWaitlist(City $city, User $passenger, Carbon $at): void
    {
        $entry = CityWaitlistEntry::firstOrCreate(['city_id' => $city->id, 'user_id' => $passenger->id]);

        if ($entry->wasRecentlyCreated) {
            $entry->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{user: User, driver: Driver, class: VehicleClass}
     */
    private function driver(City $city, array $data, int $cityIndex, int $n, VehicleClass $class, DriverStatus $status): array
    {
        [$first, $last] = $this->name($data, 'driver', $n);
        $joinedAt = Carbon::parse($city->launched_at ?? now())->subDays(mt_rand(5, 25));

        $user = User::firstOrCreate(
            ['email' => strtolower("{$first}.{$last}.{$data['slug']}@ops-demo.etigo.com")],
            [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => sprintf('+234906%02d%05d', $cityIndex, $n),
                'type' => UserType::Driver,
                'password' => $this->password,
                'phone_verified_at' => $joinedAt,
                'is_active' => true,
            ],
        );

        $isApproved = $status === DriverStatus::Approved;

        $driver = Driver::firstOrCreate(['user_id' => $user->id], [
            'city_id' => $city->id,
            'vehicle_ownership_type' => VehicleOwnershipType::OwnVehicle,
            'status' => $status,
            'kyc_status' => match ($status) {
                DriverStatus::Approved => KycStatus::Verified,
                DriverStatus::PendingReview => KycStatus::InProgress,
                default => KycStatus::NotStarted,
            },
            'kyc_verified_at' => $isApproved ? $joinedAt->copy()->addDays(2) : null,
            'licence_number' => $this->licenceNumber($data['state']),
            'is_online' => false,
            'approved_at' => $isApproved ? $joinedAt->copy()->addDays(3) : null,
        ]);

        if (! $driver->vehicle()->exists()) {
            [$make, $model, $year, $fuel] = $this->car($class->name, $n);

            Vehicle::create([
                'driver_id' => $driver->id,
                'make' => $make,
                'model' => $model,
                'colour' => ['Black', 'Silver', 'White', 'Grey', 'Dark Blue', 'Wine'][mt_rand(0, 5)],
                'plate_number' => $this->plate($data['slug']),
                'year' => $year,
                'fuel_type' => $fuel,
                'vehicle_class_id' => $class->id,
                'odometer_km' => mt_rand(38, 190) * 1000 + mt_rand(0, 999),
                'odometer_read_at' => now()->subDays(mt_rand(1, 20)),
            ]);
        }

        $this->documents($driver, $status, $joinedAt);

        if ($isApproved) {
            KycVerification::create([
                'driver_id' => $driver->id,
                'type' => KycVerificationType::Nin,
                'id_number' => (string) mt_rand(10_000_000_000, 99_999_999_999),
                'provider_reference' => 'QID-'.strtoupper(Str::random(12)),
                'status' => KycVerificationStatus::Verified,
                'match_data' => ['name_match' => mt_rand(92, 100), 'dob_match' => true],
                'verified_at' => $joinedAt->copy()->addDays(2),
            ]);
            KycVerification::create([
                'driver_id' => $driver->id,
                'type' => KycVerificationType::DriversLicense,
                'id_number' => $driver->licence_number,
                'provider_reference' => 'QID-'.strtoupper(Str::random(12)),
                'status' => KycVerificationStatus::Verified,
                'match_data' => ['name_match' => mt_rand(94, 100), 'expiry_valid' => true],
                'verified_at' => $joinedAt->copy()->addDays(2),
            ]);

            [$bankCode] = self::BANKS[mt_rand(0, count(self::BANKS) - 1)];
            BankAccount::firstOrCreate(['driver_id' => $driver->id], [
                'bank_code' => $bankCode,
                'account_number' => (string) mt_rand(1_000_000_000, 9_999_999_999),
                'account_name' => strtoupper("{$last} {$first}"),
                'verified_name' => strtoupper("{$last} {$first}"),
                'is_verified' => true,
                'is_primary' => true,
            ]);
        }

        return ['user' => $user, 'driver' => $driver->fresh(), 'class' => $class];
    }

    private function documents(Driver $driver, DriverStatus $status, Carbon $uploadedAt): void
    {
        if ($driver->documents()->exists()) {
            return;
        }

        $types = $status === DriverStatus::Onboarding
            ? [DocumentType::DrivingLicence, DocumentType::GovernmentId]
            : DocumentType::cases();

        foreach ($types as $type) {
            $approved = $status === DriverStatus::Approved;

            DriverDocument::create([
                'driver_id' => $driver->id,
                'vehicle_id' => $driver->vehicle?->id,
                'type' => $type,
                'file_path' => "driver-documents/{$driver->id}/{$type->value}.jpg",
                'original_filename' => str_replace('_', '-', $type->value).'.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => mt_rand(180_000, 2_400_000),
                // Vehicle papers are renewed yearly; a licence lasts years.
                'expires_at' => match ($type) {
                    DocumentType::DrivingLicence => $uploadedAt->copy()->addYears(mt_rand(2, 4)),
                    DocumentType::GovernmentId => null,
                    default => $uploadedAt->copy()->addMonths(mt_rand(5, 12)),
                },
                'status' => $approved ? DocumentStatus::Approved : DocumentStatus::Pending,
                'reviewed_at' => $approved ? $uploadedAt->copy()->addDays(1) : null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function passenger(City $city, array $data, int $cityIndex, int $n, bool $withActivity = true): User
    {
        [$first, $last] = $this->name($data, 'passenger', $n);
        $joinedAt = Carbon::parse($city->launched_at ?? now()->subDays(25))->subDays(mt_rand(0, 10));

        $user = User::firstOrCreate(
            ['email' => strtolower("{$first}.{$last}.{$data['slug']}@demo.etigo.com")],
            [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => sprintf('+234907%02d%05d', $cityIndex, $n),
                'type' => UserType::Passenger,
                'password' => $this->password,
                'phone_verified_at' => $joinedAt,
                'is_active' => true,
            ],
        );

        if (! $withActivity || SavedPlace::where('user_id', $user->id)->exists()) {
            return $user;
        }

        $landmarks = $data['landmarks'];
        $home = $landmarks[($n * 2) % count($landmarks)];
        $work = $landmarks[($n * 2 + 1) % count($landmarks)];

        foreach ([['home', 'Home', $home], ['work', 'Work', $work]] as [$type, $label, $place]) {
            SavedPlace::create([
                'user_id' => $user->id,
                'type' => $type,
                'name' => $label,
                'address' => $place[2],
                'latitude' => $place[0],
                'longitude' => $place[1],
            ]);
        }

        // Most riders save a card; most also keep money in the wallet.
        if ($n % 5 !== 4) {
            [$brand, $prefix] = [['visa', '4'], ['mastercard', '5'], ['verve', '5']][mt_rand(0, 2)];
            UserPaymentMethod::create([
                'user_id' => $user->id,
                'provider' => 'paystack',
                'gateway_token' => 'AUTH_'.strtolower(Str::random(10)),
                'gateway_email' => $user->email,
                'card_brand' => $brand,
                'card_last_four' => (string) mt_rand(1000, 9999),
                'card_expiry_month' => mt_rand(1, 12),
                'card_expiry_year' => mt_rand(2027, 2030),
                'is_default' => true,
            ]);
        }

        if ($n % 3 !== 2) {
            $this->topUp($user, mt_rand(10, 60) * 100_000, $joinedAt->copy()->addDays(1)->setTime(mt_rand(8, 21), mt_rand(0, 59)));
        }

        return $user;
    }

    /**
     * Two different landmarks at least 2 km apart.
     *
     * @param  list<array{0: float, 1: float, 2: string}>  $landmarks
     * @return array{0: array{0: float, 1: float, 2: string}, 1: array{0: float, 1: float, 2: string}}
     */
    private function landmarkPair(array $landmarks): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $a = $landmarks[mt_rand(0, count($landmarks) - 1)];
            $b = $landmarks[mt_rand(0, count($landmarks) - 1)];

            if ($a !== $b && $this->route($a[0], $a[1], $b[0], $b[1], 'C')['distance_km'] >= 2.0) {
                return [$a, $b];
            }
        }

        return [$landmarks[0], $landmarks[1]];
    }

    /**
     * A request time between $from and $until, mostly in the morning and
     * evening rush.
     */
    private function tripTime(Carbon $from, Carbon $until): Carbon
    {
        $day = $from->copy()->addSeconds(mt_rand(0, max(1, $until->getTimestamp() - $from->getTimestamp())))->startOfDay();
        $hour = [6, 7, 7, 8, 8, 9, 11, 13, 15, 16, 17, 17, 18, 18, 19, 20, 21, 22][mt_rand(0, 17)];
        $at = $day->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));

        return $at->min($until)->max($from);
    }

    private function paymentFor(User $passenger): PaymentMethod
    {
        $roll = mt_rand(1, 100);
        $hasCard = UserPaymentMethod::where('user_id', $passenger->id)->exists();
        $walletKobo = app(WalletPaymentService::class)->availableBalance($passenger);

        return match (true) {
            $roll <= 25 && $walletKobo >= 2_500_000 => PaymentMethod::Wallet,
            $roll <= 60 && $hasCard => PaymentMethod::Card,
            default => PaymentMethod::Cash,
        };
    }

    /**
     * Most trips complete; some are cancelled by either side or find no
     * driver.
     *
     * @return array<string, mixed>
     */
    private function outcome(): array
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 84 => ['status' => 'completed'],
            $roll <= 91 => ['status' => 'cancelled', 'cancelled_by' => 'passenger', 'cancellation_reason' => [
                CancellationReason::ChangedMind, CancellationReason::DriverTooFar, CancellationReason::WaitTooLong, CancellationReason::FoundAlternative,
            ][mt_rand(0, 3)]->value],
            $roll <= 95 => ['status' => 'cancelled', 'cancelled_by' => 'driver', 'cancellation_reason' => [
                CancellationReason::PassengerNoShow, CancellationReason::WrongPickup,
            ][mt_rand(0, 1)]->value],
            default => ['status' => 'no_driver_found'],
        };
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function passengerRating(): array
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 58 => [5, [null, 'Smooth ride, very polite driver.', 'Clean car and the AC was working.', 'Arrived early and knew the route well.', 'Great driver, will ride again.'][mt_rand(0, 4)]],
            $roll <= 86 => [4, [null, 'Good trip, a bit of traffic on the way.', 'Fine ride, driver called to confirm pickup.'][mt_rand(0, 2)]],
            $roll <= 95 => [3, ['Driver took a longer route.', 'AC was not working well.', 'Took a while to find the pickup point.'][mt_rand(0, 2)]],
            default => [2, ['Driver was on the phone throughout the trip.', 'Car was not clean.'][mt_rand(0, 1)]],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function thirdPartyRider(int $t): array
    {
        return [['Mrs Folake Adeyemi', 'Mr Chinedu Okafor', 'Grace Edet'][$t % 3], sprintf('+23490800%05d', $t)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}
     */
    private function name(array $data, string $role, int $n): array
    {
        [$firsts, $lasts] = self::NAMES[$this->namePool($data)];

        // Drivers and passengers draw from different ends of the lists so a
        // city never has a driver and a passenger with the same name.
        $offset = $role === 'driver' ? 0 : 7;

        return [$firsts[($n + $offset) % count($firsts)], $lasts[($n * 3 + $offset) % count($lasts)]];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function namePool(array $data): string
    {
        return match ($data['slug']) {
            'port-harcourt', 'uyo', 'calabar', 'warri' => 'south-south',
            'benin-city', 'asaba' => 'edo-delta',
            'ilorin' => 'ilorin',
            'abuja', 'jos' => 'north-central',
            'kano', 'kaduna' => 'north',
            default => $data['region'] === 'South-East' ? 'igbo' : 'yoruba',
        };
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: FuelType}
     */
    private function car(string $class, int $n): array
    {
        $cars = [
            'economy' => [
                ['Toyota', 'Corolla', 2016, FuelType::Petrol], ['Toyota', 'Corolla', 2014, FuelType::Cng],
                ['Kia', 'Rio', 2017, FuelType::Petrol], ['Hyundai', 'Elantra', 2016, FuelType::Petrol],
                ['Nissan', 'Almera', 2015, FuelType::Petrol], ['Innoson', 'IVM Fox', 2020, FuelType::Petrol],
                ['Honda', 'Civic', 2015, FuelType::Petrol],
            ],
            'comfort' => [
                ['Toyota', 'Camry', 2018, FuelType::Petrol], ['Honda', 'Accord', 2017, FuelType::Petrol],
                ['Toyota', 'Corolla Hybrid', 2021, FuelType::Hybrid], ['Hyundai', 'Sonata', 2018, FuelType::Petrol],
            ],
            'premium' => [
                ['Lexus', 'ES 350', 2019, FuelType::Petrol], ['Hyundai', 'Kona Electric', 2022, FuelType::Electric],
                ['Mercedes-Benz', 'C300', 2018, FuelType::Petrol],
            ],
            'suv' => [
                ['Toyota', 'Sienna', 2016, FuelType::Cng], ['Toyota', 'Highlander', 2017, FuelType::Petrol],
                ['Lexus', 'RX 350', 2018, FuelType::Petrol],
            ],
        ];

        $options = $cars[$class] ?? $cars['economy'];

        return $options[($n + mt_rand(0, count($options) - 1)) % count($options)];
    }

    /**
     * A plate in the national format (LGA code, three digits, two letters).
     */
    private function plate(string $slug): string
    {
        $codes = self::PLATE_CODES[$slug] ?? ['XXX'];

        do {
            $plate = sprintf('%s-%03d%s', $codes[mt_rand(0, count($codes) - 1)], mt_rand(100, 999), chr(mt_rand(65, 90)).chr(mt_rand(65, 90)));
        } while (Vehicle::where('plate_number', $plate)->exists());

        return $plate;
    }

    /**
     * An FRSC-style licence number: state code, digits, letters, digits.
     */
    private function licenceNumber(string $state): string
    {
        $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $state), 0, 3));

        return sprintf('%s%05d%s%02d', $code, mt_rand(10000, 99999), chr(mt_rand(65, 90)).chr(mt_rand(65, 90)), mt_rand(10, 99));
    }

    private function seedCityPromos(): void
    {
        $promos = [
            ['ILORIN500', 'Ilorin: ₦500 off your next 3 rides.', 'flat', 500, null, 3, ['ilorin'], 30],
            ['COALCITY20', 'Enugu: 20% off, up to ₦1,000.', 'percentage', 20, 1000, 2, ['enugu'], 21],
            ['GARDENCITY15', 'Port Harcourt: 15% off, up to ₦1,500.', 'percentage', 15, 1500, 2, ['port-harcourt'], 45],
            ['BENINLAUNCH', 'Benin City launch: 50% off your first ride, up to ₦1,000.', 'percentage', 50, 1000, 1, ['benin-city'], 60],
        ];

        $admin = User::where('email', 'admin@etigo.com')->first();

        foreach ($promos as [$code, $description, $type, $value, $cap, $perUser, $slugs, $days]) {
            $cities = City::whereIn('slug', $slugs)->get();

            if ($cities->isEmpty() || PromoCode::where('code', $code)->exists()) {
                continue;
            }

            $startsAt = $cities->first()->launched_at ?? Carbon::parse($cities->first()->launch_date ?? now());

            $promo = PromoCode::create([
                'code' => $code,
                'description' => $description,
                'discount_type' => $type,
                'discount_value' => $value,
                'max_discount_cap' => $cap,
                'total_redemption_limit' => 2000,
                'per_user_limit' => $perUser,
                'starts_at' => $startsAt,
                'expires_at' => Carbon::parse($startsAt)->addDays($days),
                'is_active' => true,
                'peak_only' => false,
                'off_peak_only' => false,
                'created_by_admin_id' => $admin?->id,
            ]);

            $promo->cities()->sync($cities->modelKeys());
        }
    }

    /**
     * Nigerian bank codes (CBN / NIBSS) for driver payout accounts.
     */
    private const BANKS = [
        ['058'], ['044'], ['057'], ['033'], ['011'], ['999992'], ['50515'], ['50211'], ['232'], ['035'],
    ];

    /**
     * LGA plate codes per city.
     */
    private const PLATE_CODES = [
        'lagos' => ['KJA', 'LND', 'EKY', 'AAA', 'SMK', 'APP', 'FKJ', 'KTU'],
        'abuja' => ['ABC', 'BWR', 'GWA', 'KUJ'],
        'port-harcourt' => ['PHC', 'RUM', 'OBK'],
        'ibadan' => ['IBD', 'BDJ', 'AKN'],
        'abeokuta' => ['ABK', 'AKM'],
        'ilorin' => ['ILR', 'ASA'],
        'ado-ekiti' => ['ADK'],
        'akure' => ['AKR'],
        'enugu' => ['ENU', 'AWK'],
        'aba' => ['ABA'],
        'umuahia' => ['UMH'],
        'benin-city' => ['BEN', 'EKP'],
        'owerri' => ['OWR'],
        'uyo' => ['UYY'],
        'calabar' => ['CAL'],
        'warri' => ['WRR', 'EFR'],
        'asaba' => ['ASB'],
        'onitsha' => ['ONT'],
        'kano' => ['KMC', 'NSR'],
        'kaduna' => ['KDN', 'KKN'],
        'jos' => ['JJJ', 'BKR'],
        'osogbo' => ['OSG'],
    ];

    /**
     * First and last names by area.
     */
    private const NAMES = [
        'yoruba' => [
            ['Adebayo', 'Temitope', 'Oluwaseun', 'Funmilayo', 'Kehinde', 'Babatunde', 'Yetunde', 'Adeola', 'Damilola', 'Opeyemi', 'Ayodele', 'Folake', 'Gbenga', 'Tolulope', 'Bolaji'],
            ['Adeyemi', 'Ogunbanjo', 'Olawale', 'Akinwale', 'Adekunle', 'Ogunleye', 'Bamidele', 'Oyelaran', 'Adebisi', 'Afolabi', 'Oladipo', 'Ajayi', 'Ogundipe', 'Akintola'],
        ],
        'ilorin' => [
            ['Abdulrasheed', 'Kafayat', 'Ibrahim', 'Aishat', 'Sulaiman', 'Rukayat', 'Mustapha', 'Hafsat', 'Abdulganiyu', 'Basirat', 'Yusuf', 'Zainab'],
            ['Alabi', 'Saliu', 'Abdulkadir', 'Ayinde', 'Olayiwola', 'Jimoh', 'Adebayo', 'Yusuf', 'Bello', 'Oyewole'],
        ],
        'igbo' => [
            ['Chinedu', 'Ngozi', 'Obinna', 'Chiamaka', 'Emeka', 'Ifeoma', 'Nnamdi', 'Adaeze', 'Uchenna', 'Kelechi', 'Amarachi', 'Ikenna', 'Nkechi', 'Chukwuemeka'],
            ['Okafor', 'Eze', 'Nwosu', 'Okonkwo', 'Obi', 'Nwachukwu', 'Okeke', 'Udeh', 'Anyanwu', 'Ezeh', 'Onyeka', 'Igwe', 'Uzor', 'Mbah'],
        ],
        'south-south' => [
            ['Tamunosiki', 'Ibinabo', 'Soala', 'Boma', 'Precious', 'Godspower', 'Blessing', 'Ebiye', 'Ekaette', 'Ubong', 'Ini', 'Ovie', 'Tonye', 'Aniekan'],
            ['Amadi', 'Wokocha', 'Briggs', 'George', 'Douglas', 'Ihunwo', 'Akpan', 'Etim', 'Edet', 'Okon', 'Omasoro', 'Dappa', 'Jumbo', 'Ekpo'],
        ],
        'edo-delta' => [
            ['Osaro', 'Efosa', 'Osas', 'Eghosa', 'Itohan', 'Osagie', 'Ivie', 'Ejiro', 'Oghenekaro', 'Onome', 'Nosa', 'Uyi'],
            ['Igbinedion', 'Osagie', 'Omoregie', 'Aigbe', 'Ehigiator', 'Ogbemudia', 'Okoro', 'Oghene', 'Ighodaro', 'Enabulele'],
        ],
        'north-central' => [
            ['Musa', 'Aisha', 'Ibrahim', 'Fatima', 'Danjuma', 'Hauwa', 'Emmanuel', 'Ladi', 'Yakubu', 'Ruth', 'Daniel', 'Maryam', 'Samuel', 'Halima'],
            ['Bello', 'Abubakar', 'Danjuma', 'Gyang', 'Pam', 'Yakubu', 'Audu', 'Lawal', 'Garba', 'Suleiman', 'Dung', 'Ahmed'],
        ],
        'north' => [
            ['Abdullahi', 'Zainab', 'Usman', 'Hadiza', 'Yusuf', 'Maryam', 'Sani', 'Halima', 'Aminu', 'Khadija', 'Bashir', 'Rabi'],
            ['Mohammed', 'Usman', 'Danladi', 'Lawal', 'Garba', 'Abdulkarim', 'Shehu', 'Bala', 'Inuwa', 'Dahiru'],
        ],
    ];
}
