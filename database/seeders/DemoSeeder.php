<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\DisputeCategory;
use App\Enums\DisputeOutcome;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\EvStationStatus;
use App\Enums\FleetAgreementStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\NotificationCategory;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SanctionTier;
use App\Enums\SosTriggerType;
use App\Enums\SurgeType;
use App\Enums\UserType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\BankAccount;
use App\Models\City;
use App\Models\DailyRemittance;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\FleetAgreement;
use App\Models\GamificationProfile;
use App\Models\KycVerification;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\OfflineTripFlag;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\SavedPlace;
use App\Models\SurgeRule;
use App\Models\TripCarbonScore;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping DemoSeeder in production.');

            return;
        }

        $password = Hash::make('Qwer!234');

        $this->seedVehicleClasses();
        $this->seedCities();
        $passengers = $this->seedPassengers($password);
        $drivers = $this->seedDrivers($password);
        $this->seedPaymentMethods($passengers);
        $this->seedPromoCodes();
        $rides = $this->seedRides($passengers, $drivers);
        $this->seedRatings($rides);
        $this->seedDisputes($rides);
        $this->seedNotifications($passengers, $drivers);
        $this->seedSavedPlaces($passengers);
        $this->seedDisputeNotes();
        $this->seedSurgeRules();
        $this->seedKycVerifications($drivers);
        $this->seedGamificationProfiles($passengers, $drivers);
        $this->seedTripCarbonScores($rides);
        $this->seedNotificationPreferences($passengers, $drivers);
        $this->seedAppSettings();
        $this->seedPromoRedemptions($passengers, $rides);
        $this->seedBankAccounts($drivers);
        $this->seedEvCharging($drivers);
        $this->seedFleetAgreements($drivers);
        $this->seedSosIncident($rides);
        $this->seedWalletAccounts($passengers, $drivers);
        $this->seedOfflineTripFlags($rides, $drivers);
    }

    private function seedVehicleClasses(): void
    {
        $classes = [
            ['name' => 'economy', 'display_name' => 'Economy', 'capacity' => 4, 'description' => 'Affordable rides for everyday trips.'],
            ['name' => 'comfort', 'display_name' => 'Comfort', 'capacity' => 4, 'description' => 'A step up in comfort with newer vehicles.'],
            ['name' => 'premium', 'display_name' => 'Premium', 'capacity' => 4, 'description' => 'Luxury vehicles for a premium experience.'],
            ['name' => 'suv', 'display_name' => 'SUV', 'capacity' => 6, 'description' => 'Spacious SUVs for groups and luggage.'],
        ];

        foreach ($classes as $class) {
            VehicleClass::firstOrCreate(
                ['name' => $class['name']],
                $class + ['is_active' => true],
            );
        }
    }

    private function seedCities(): void
    {
        $vehicleClasses = VehicleClass::all();

        $cities = [
            [
                'name' => 'Lagos',
                'slug' => 'lagos',
                'state' => 'Lagos',
                'region' => 'South-West',
                'timezone' => 'Africa/Lagos',
                'currency_code' => 'NGN',
                'is_active' => true,
                'boundary' => [
                    'type' => 'Point',
                    'coordinates' => [3.3792, 6.5244],
                    'radius_km' => 40,
                ],
            ],
            [
                'name' => 'Abuja',
                'slug' => 'abuja',
                'state' => 'FCT',
                'region' => 'North-Central',
                'timezone' => 'Africa/Lagos',
                'currency_code' => 'NGN',
                'is_active' => true,
                'boundary' => [
                    'type' => 'Point',
                    'coordinates' => [7.4951, 9.0579],
                    'radius_km' => 30,
                ],
            ],
            [
                'name' => 'Port Harcourt',
                'slug' => 'port-harcourt',
                'state' => 'Rivers',
                'region' => 'South-South',
                'timezone' => 'Africa/Lagos',
                'currency_code' => 'NGN',
                'is_active' => true,
                'boundary' => [
                    'type' => 'Point',
                    'coordinates' => [7.0498, 4.8156],
                    'radius_km' => 20,
                ],
            ],
            [
                'name' => 'Ibadan',
                'slug' => 'ibadan',
                'state' => 'Oyo',
                'region' => 'South-West',
                'timezone' => 'Africa/Lagos',
                'currency_code' => 'NGN',
                'is_active' => false,
                'boundary' => [
                    'type' => 'Point',
                    'coordinates' => [3.9470, 7.3775],
                    'radius_km' => 20,
                ],
            ],
        ];

        foreach ($cities as $cityData) {
            $city = City::firstOrCreate(
                ['slug' => $cityData['slug']],
                $cityData,
            );

            if (! $city->wasRecentlyCreated) {
                $city->updateQuietly(array_filter([
                    'state' => $city->state ?? $cityData['state'],
                    'region' => $city->region ?? $cityData['region'],
                ]));
            }

            if ($city->vehicleClasses()->count() === 0) {
                $syncData = [];
                foreach ($vehicleClasses as $i => $vc) {
                    $syncData[$vc->id] = ['is_active' => true, 'sort_order' => $i + 1];
                }
                $city->vehicleClasses()->sync($syncData);
            }
        }
    }

    /**
     * @return array<string, User>
     */
    private function seedPassengers(string $password): array
    {
        $passengers = [
            'ade' => ['first_name' => 'Ade', 'last_name' => 'Ogunleye', 'phone' => '+2348100000001', 'email' => 'ade@demo.etigo.com'],
            'ngozi' => ['first_name' => 'Ngozi', 'last_name' => 'Okafor', 'phone' => '+2348100000002', 'email' => 'ngozi@demo.etigo.com'],
            'emeka' => ['first_name' => 'Emeka', 'last_name' => 'Nwosu', 'phone' => '+2348100000003', 'email' => 'emeka@demo.etigo.com'],
            'funmi' => ['first_name' => 'Funmi', 'last_name' => 'Adeyemi', 'phone' => '+2348100000004', 'email' => 'funmi@demo.etigo.com'],
            'chidi' => ['first_name' => 'Chidi', 'last_name' => 'Eze', 'phone' => '+2348100000005', 'email' => 'chidi@demo.etigo.com'],
        ];

        $result = [];

        foreach ($passengers as $key => $data) {
            $result[$key] = User::firstOrCreate(
                ['email' => $data['email']],
                $data + [
                    'type' => UserType::Passenger,
                    'password' => $password,
                    'phone_verified_at' => now(),
                    'is_active' => true,
                ],
            );
        }

        return $result;
    }

    /**
     * @return array<string, array{user: User, driver: Driver}>
     */
    private function seedDrivers(string $password): array
    {
        $economyClass = VehicleClass::where('name', 'economy')->first();
        $comfortClass = VehicleClass::where('name', 'comfort')->first();
        $premiumClass = VehicleClass::where('name', 'premium')->first();
        $suvClass = VehicleClass::where('name', 'suv')->first();

        $drivers = [
            'bayo' => [
                'user' => ['first_name' => 'Bayo', 'last_name' => 'Akinola', 'phone' => '+2348200000001', 'email' => 'bayo@demo.etigo.com'],
                'driver' => ['licence_number' => 'LA123ABC', 'status' => DriverStatus::Approved, 'approved_at' => now()->subDays(30), 'is_online' => true],
                'vehicle' => ['make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'White', 'plate_number' => 'LAG-001AB', 'year' => 2022, 'vehicle_class_id' => $economyClass?->id],
            ],
            'kemi' => [
                'user' => ['first_name' => 'Kemi', 'last_name' => 'Bakare', 'phone' => '+2348200000002', 'email' => 'kemi@demo.etigo.com'],
                'driver' => ['licence_number' => 'LA456DEF', 'status' => DriverStatus::Approved, 'approved_at' => now()->subDays(15), 'is_online' => false],
                'vehicle' => ['make' => 'Honda', 'model' => 'Accord', 'colour' => 'Black', 'plate_number' => 'LAG-002CD', 'year' => 2023, 'vehicle_class_id' => $comfortClass?->id],
            ],
            'segun' => [
                'user' => ['first_name' => 'Segun', 'last_name' => 'Obaseki', 'phone' => '+2348200000003', 'email' => 'segun@demo.etigo.com'],
                'driver' => ['licence_number' => 'AB789GHI', 'status' => DriverStatus::Approved, 'approved_at' => now()->subDays(45), 'is_online' => true],
                'vehicle' => ['make' => 'Mercedes-Benz', 'model' => 'E-Class', 'colour' => 'Silver', 'plate_number' => 'ABJ-003EF', 'year' => 2024, 'vehicle_class_id' => $premiumClass?->id],
            ],
            'amara' => [
                'user' => ['first_name' => 'Amara', 'last_name' => 'Nnamdi', 'phone' => '+2348200000004', 'email' => 'amara@demo.etigo.com'],
                'driver' => ['licence_number' => 'AB012JKL', 'status' => DriverStatus::PendingReview, 'is_online' => false],
                'vehicle' => ['make' => 'Toyota', 'model' => 'Highlander', 'colour' => 'Blue', 'plate_number' => 'ABJ-004GH', 'year' => 2021, 'vehicle_class_id' => $suvClass?->id],
            ],
            'tunde' => [
                'user' => ['first_name' => 'Tunde', 'last_name' => 'Fashola', 'phone' => '+2348200000005', 'email' => 'tunde@demo.etigo.com'],
                'driver' => ['licence_number' => 'LA345MNO', 'status' => DriverStatus::Suspended, 'approved_at' => now()->subDays(60), 'suspended_at' => now()->subDays(5), 'is_online' => false],
                'vehicle' => ['make' => 'Nissan', 'model' => 'Sentra', 'colour' => 'Red', 'plate_number' => 'LAG-005IJ', 'year' => 2019, 'vehicle_class_id' => $economyClass?->id],
            ],
            'ify' => [
                'user' => ['first_name' => 'Ify', 'last_name' => 'Okoro', 'phone' => '+2348200000006', 'email' => 'ify@demo.etigo.com'],
                'driver' => ['licence_number' => 'PH678PQR', 'status' => DriverStatus::Rejected, 'rejection_reason' => 'Documents are blurry and unreadable.', 'is_online' => false],
                'vehicle' => null,
            ],
        ];

        $result = [];

        foreach ($drivers as $key => $data) {
            $user = User::firstOrCreate(
                ['email' => $data['user']['email']],
                $data['user'] + [
                    'type' => UserType::Driver,
                    'password' => $password,
                    'phone_verified_at' => now(),
                    'is_active' => true,
                ],
            );

            $driver = Driver::firstOrCreate(
                ['user_id' => $user->id],
                $data['driver'],
            );

            if ($data['vehicle'] && ! $driver->vehicle()->exists()) {
                Vehicle::create($data['vehicle'] + ['driver_id' => $driver->id]);
            }

            $this->seedDriverDocuments($driver, $data['driver']['status']);

            $result[$key] = ['user' => $user, 'driver' => $driver];
        }

        return $result;
    }

    private function seedDriverDocuments(Driver $driver, DriverStatus $status): void
    {
        if ($driver->documents()->exists()) {
            return;
        }

        $docStatus = match ($status) {
            DriverStatus::Approved => DocumentStatus::Approved,
            DriverStatus::Rejected => DocumentStatus::Rejected,
            default => DocumentStatus::Pending,
        };

        foreach (DocumentType::cases() as $type) {
            DriverDocument::create([
                'driver_id' => $driver->id,
                'type' => $type,
                'file_path' => "driver-documents/{$driver->id}/{$type->value}.pdf",
                'original_filename' => $type->value.'.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => rand(50000, 500000),
                'status' => $docStatus,
                'rejection_reason' => $docStatus === DocumentStatus::Rejected ? 'Document is blurry and unreadable.' : null,
                'reviewed_at' => $docStatus !== DocumentStatus::Pending ? now()->subDays(rand(1, 10)) : null,
            ]);
        }
    }

    /**
     * @param  array<string, User>  $passengers
     */
    private function seedPaymentMethods(array $passengers): void
    {
        if (UserPaymentMethod::whereIn('user_id', collect($passengers)->pluck('id'))->exists()) {
            return;
        }

        $cards = [
            ['brand' => 'visa', 'last_four' => '4242'],
            ['brand' => 'mastercard', 'last_four' => '8210'],
            ['brand' => 'visa', 'last_four' => '1234'],
            ['brand' => 'mastercard', 'last_four' => '5678'],
            ['brand' => 'verve', 'last_four' => '9012'],
        ];

        $i = 0;
        foreach ($passengers as $passenger) {
            $card = $cards[$i % count($cards)];
            UserPaymentMethod::create([
                'user_id' => $passenger->id,
                'gateway_token' => 'PSK_'.Str::random(24),
                'card_brand' => $card['brand'],
                'card_last_four' => $card['last_four'],
                'card_expiry_month' => rand(1, 12),
                'card_expiry_year' => rand(2027, 2030),
                'is_default' => true,
            ]);
            $i++;
        }
    }

    private function seedPromoCodes(): void
    {
        if (DB::table('promo_codes')->exists()) {
            return;
        }

        $promos = [
            [
                'code' => 'WELCOME50',
                'discount_type' => 'percentage',
                'discount_value' => 50.00,
                'max_discount_cap' => 2000.00,
                'total_redemption_limit' => 1000,
                'per_user_limit' => 1,
                'starts_at' => now()->subDays(30),
                'expires_at' => now()->addDays(60),
                'is_active' => true,
                'peak_only' => false,
                'off_peak_only' => false,
            ],
            [
                'code' => 'RIDE500',
                'discount_type' => 'flat',
                'discount_value' => 500.00,
                'max_discount_cap' => null,
                'total_redemption_limit' => 500,
                'per_user_limit' => 3,
                'starts_at' => now()->subDays(7),
                'expires_at' => now()->addDays(30),
                'is_active' => true,
                'peak_only' => false,
                'off_peak_only' => false,
            ],
            [
                'code' => 'OFFPEAK20',
                'discount_type' => 'percentage',
                'discount_value' => 20.00,
                'max_discount_cap' => 1500.00,
                'total_redemption_limit' => null,
                'per_user_limit' => 5,
                'starts_at' => now()->subDays(14),
                'expires_at' => now()->addDays(45),
                'is_active' => true,
                'peak_only' => false,
                'off_peak_only' => true,
            ],
            [
                'code' => 'EXPIRED10',
                'discount_type' => 'percentage',
                'discount_value' => 10.00,
                'max_discount_cap' => 1000.00,
                'total_redemption_limit' => 200,
                'per_user_limit' => 1,
                'starts_at' => now()->subDays(60),
                'expires_at' => now()->subDays(5),
                'is_active' => false,
                'peak_only' => false,
                'off_peak_only' => false,
            ],
        ];

        foreach ($promos as $promo) {
            DB::table('promo_codes')->insert($promo + [
                'id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, User>  $passengers
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     * @return array<int, Ride>
     */
    private function seedRides(array $passengers, array $drivers): array
    {
        if (Ride::exists()) {
            return Ride::all()->all();
        }

        $lagos = City::where('slug', 'lagos')->first();
        $abuja = City::where('slug', 'abuja')->first();
        $economyClass = VehicleClass::where('name', 'economy')->first();
        $comfortClass = VehicleClass::where('name', 'comfort')->first();
        $premiumClass = VehicleClass::where('name', 'premium')->first();

        if (! $lagos || ! $abuja || ! $economyClass) {
            return [];
        }

        $lagosRoutes = [
            ['pickup' => [6.5244, 3.3792, 'Ikeja City Mall, Alausa'], 'dest' => [6.4281, 3.4219, 'Victoria Island, Adeola Odeku']],
            ['pickup' => [6.4355, 3.4167, 'Lekki Phase 1, Admiralty Way'], 'dest' => [6.5959, 3.3473, 'Murtala Muhammed Airport']],
            ['pickup' => [6.4541, 3.3947, 'Yaba, University of Lagos'], 'dest' => [6.5116, 3.3565, 'Ikeja GRA, Joel Ogunnaike']],
            ['pickup' => [6.4698, 3.2816, 'Surulere, National Stadium'], 'dest' => [6.4281, 3.4219, 'Ikoyi, Bourdillon Road']],
            ['pickup' => [6.5922, 3.3413, 'Airport Road, Ikeja'], 'dest' => [6.4355, 3.4167, 'Lekki Phase 1']],
        ];

        $abujaRoutes = [
            ['pickup' => [9.0579, 7.4951, 'Wuse 2, Aminu Kano Crescent'], 'dest' => [9.0765, 7.3986, 'Nnamdi Azikiwe Airport']],
            ['pickup' => [9.0643, 7.4892, 'Garki, Area 11'], 'dest' => [9.0246, 7.4939, 'Maitama, Aguiyi Ironsi Street']],
        ];

        $rideData = [
            [
                'passenger' => $passengers['ade'], 'driver' => $drivers['bayo'],
                'city' => $lagos, 'class' => $economyClass, 'route' => $lagosRoutes[0],
                'status' => 'completed', 'fare' => 3500.00, 'payment_status' => PaymentStatus::Settled->value,
                'created' => now()->subDays(7), 'started' => now()->subDays(7)->addMinutes(5), 'completed' => now()->subDays(7)->addMinutes(35),
            ],
            [
                'passenger' => $passengers['ngozi'], 'driver' => $drivers['kemi'],
                'city' => $lagos, 'class' => $comfortClass, 'route' => $lagosRoutes[1],
                'status' => 'completed', 'fare' => 5200.00, 'payment_status' => PaymentStatus::Settled->value,
                'created' => now()->subDays(5), 'started' => now()->subDays(5)->addMinutes(8), 'completed' => now()->subDays(5)->addMinutes(50),
            ],
            [
                'passenger' => $passengers['emeka'], 'driver' => $drivers['segun'],
                'city' => $abuja, 'class' => $premiumClass, 'route' => $abujaRoutes[0],
                'status' => 'completed', 'fare' => 8500.00, 'payment_status' => PaymentStatus::Settled->value,
                'created' => now()->subDays(3), 'started' => now()->subDays(3)->addMinutes(4), 'completed' => now()->subDays(3)->addMinutes(40),
            ],
            [
                'passenger' => $passengers['ade'], 'driver' => $drivers['bayo'],
                'city' => $lagos, 'class' => $economyClass, 'route' => $lagosRoutes[2],
                'status' => 'completed', 'fare' => 2800.00, 'payment_status' => PaymentStatus::Settled->value,
                'created' => now()->subDays(2), 'started' => now()->subDays(2)->addMinutes(6), 'completed' => now()->subDays(2)->addMinutes(25),
            ],
            [
                'passenger' => $passengers['funmi'], 'driver' => $drivers['kemi'],
                'city' => $lagos, 'class' => $comfortClass, 'route' => $lagosRoutes[3],
                'status' => 'completed', 'fare' => 4100.00, 'payment_status' => PaymentStatus::Settled->value,
                'created' => now()->subDays(1), 'started' => now()->subDays(1)->addMinutes(7), 'completed' => now()->subDays(1)->addMinutes(30),
            ],
            [
                'passenger' => $passengers['chidi'], 'driver' => $drivers['segun'],
                'city' => $abuja, 'class' => $premiumClass, 'route' => $abujaRoutes[1],
                'status' => 'completed', 'fare' => 6000.00, 'payment_status' => PaymentStatus::Settled->value,
                'created' => now()->subDays(1), 'started' => now()->subDays(1)->addMinutes(3), 'completed' => now()->subDays(1)->addMinutes(20),
            ],
            [
                'passenger' => $passengers['ngozi'], 'driver' => $drivers['bayo'],
                'city' => $lagos, 'class' => $economyClass, 'route' => $lagosRoutes[4],
                'status' => 'cancelled', 'fare' => null, 'payment_status' => PaymentStatus::Pending->value,
                'created' => now()->subDays(4), 'started' => null, 'completed' => null,
                'cancelled_by' => $passengers['ngozi']->id, 'cancellation_reason' => 'Driver was taking too long to arrive.',
            ],
            [
                'passenger' => $passengers['funmi'], 'driver' => $drivers['bayo'],
                'city' => $lagos, 'class' => $economyClass, 'route' => $lagosRoutes[2],
                'status' => 'completed', 'fare' => 2200.00, 'payment_status' => PaymentStatus::Collected->value,
                'payment_method' => PaymentMethod::Cash,
                'created' => now()->subDays(3), 'started' => now()->subDays(3)->addMinutes(5), 'completed' => now()->subDays(3)->addMinutes(28),
            ],
            [
                'passenger' => $passengers['ngozi'], 'driver' => $drivers['kemi'],
                'city' => $lagos, 'class' => $comfortClass, 'route' => $lagosRoutes[3],
                'status' => 'completed', 'fare' => 4800.00, 'payment_status' => PaymentStatus::Settled->value,
                'payment_method' => PaymentMethod::Wallet,
                'created' => now()->subDays(2), 'started' => now()->subDays(2)->addMinutes(4), 'completed' => now()->subDays(2)->addMinutes(35),
            ],
            [
                'passenger' => $passengers['ade'], 'driver' => $drivers['bayo'],
                'city' => $lagos, 'class' => $economyClass, 'route' => $lagosRoutes[0],
                'status' => 'in_progress', 'fare' => null, 'payment_status' => 'pending',
                'created' => now()->subMinutes(15), 'started' => now()->subMinutes(10), 'completed' => null,
            ],
        ];

        $rides = [];

        foreach ($rideData as $data) {
            $pricingSnapshot = [
                'base_fare' => match ($data['class']->name) {
                    'economy' => 600, 'comfort' => 800, 'premium' => 1200, default => 600,
                },
                'per_km_rate' => match ($data['class']->name) {
                    'economy' => 250, 'comfort' => 350, 'premium' => 500, default => 250,
                },
                'per_minute_rate' => match ($data['class']->name) {
                    'economy' => 40, 'comfort' => 55, 'premium' => 80, default => 40,
                },
                'minimum_fare' => match ($data['class']->name) {
                    'economy' => 1500, 'comfort' => 2000, 'premium' => 3000, default => 1500,
                },
            ];

            $ride = Ride::create([
                'city_id' => $data['city']->id,
                'vehicle_class_id' => $data['class']->id,
                'passenger_id' => $data['passenger']->id,
                'driver_id' => $data['driver']['user']->id,
                'pickup_lat' => $data['route']['pickup'][0],
                'pickup_lng' => $data['route']['pickup'][1],
                'pickup_address' => $data['route']['pickup'][2],
                'destination_lat' => $data['route']['dest'][0],
                'destination_lng' => $data['route']['dest'][1],
                'destination_address' => $data['route']['dest'][2],
                'status' => $data['status'],
                'pin_code' => str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT),
                'share_token' => Str::random(32),
                'fare_estimate_amount' => $data['fare'] ?? $pricingSnapshot['minimum_fare'],
                'final_fare_amount' => $data['status'] === 'completed' ? $data['fare'] : null,
                'fare_currency' => 'NGN',
                'pricing_snapshot' => $pricingSnapshot,
                'payment_method' => isset($data['payment_method']) ? $data['payment_method']->value : PaymentMethod::Card->value,
                'payment_status' => $data['payment_status'],
                'cancelled_by' => $data['cancelled_by'] ?? null,
                'cancellation_reason' => $data['cancellation_reason'] ?? null,
                'matched_at' => $data['created']->copy()->addMinutes(2),
                'started_at' => $data['started'],
                'completed_at' => $data['completed'],
                'created_at' => $data['created'],
                'updated_at' => $data['completed'] ?? $data['started'] ?? $data['created'],
            ]);

            $this->seedRideTransitions($ride, $data);

            if ($data['status'] === 'completed' && $data['fare']) {
                $paymentMethod = $data['payment_method'] ?? PaymentMethod::Card;
                Payment::create([
                    'ride_id' => $ride->id,
                    'amount' => $data['fare'],
                    'currency' => 'NGN',
                    'method' => $paymentMethod->value,
                    'gateway_transaction_id' => $paymentMethod === PaymentMethod::Cash ? null : 'PSK_TXN_'.Str::random(16),
                    'gateway_payment_method_id' => $paymentMethod === PaymentMethod::Cash ? null : 'PSK_PM_'.Str::random(12),
                    'tip_amount' => rand(0, 3) === 0 ? rand(2, 10) * 100 : 0,
                    'status' => $data['payment_status'],
                    'created_at' => $data['completed'],
                    'updated_at' => $data['completed'],
                ]);
            }

            $rides[] = $ride;
        }

        return $rides;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedRideTransitions(Ride $ride, array $data): void
    {
        $transitions = [
            ['from' => null, 'to' => 'requested', 'at' => $data['created']],
            ['from' => 'requested', 'to' => 'matched', 'at' => $data['created']->copy()->addMinutes(2)],
        ];

        if ($data['started']) {
            $transitions[] = ['from' => 'matched', 'to' => 'driver_arrived', 'at' => $data['started']->copy()->subMinutes(2)];
            $transitions[] = ['from' => 'driver_arrived', 'to' => 'in_progress', 'at' => $data['started']];
        }

        if ($data['completed']) {
            $transitions[] = ['from' => 'in_progress', 'to' => 'completed', 'at' => $data['completed']];
        }

        if ($data['status'] === 'cancelled') {
            $transitions[] = ['from' => 'matched', 'to' => 'cancelled', 'at' => $data['created']->copy()->addMinutes(8)];
        }

        foreach ($transitions as $t) {
            DB::table('ride_state_transitions')->insert([
                'id' => (string) Str::uuid(),
                'ride_id' => $ride->id,
                'from_state' => $t['from'],
                'to_state' => $t['to'],
                'triggered_by_type' => 'App\\Models\\User',
                'triggered_by_id' => $ride->passenger_id,
                'metadata' => json_encode([]),
                'created_at' => $t['at'],
            ]);
        }
    }

    /**
     * @param  array<int, Ride>  $rides
     */
    private function seedRatings(array $rides): void
    {
        if (DB::table('ratings')->exists()) {
            return;
        }

        foreach ($rides as $ride) {
            if ($ride->status !== 'completed') {
                continue;
            }

            DB::table('ratings')->insert([
                'id' => (string) Str::uuid(),
                'ride_id' => $ride->id,
                'rated_by_user_id' => $ride->passenger_id,
                'rated_user_id' => $ride->driver_id,
                'score' => rand(3, 5),
                'comment' => collect([
                    'Great ride, very smooth!',
                    'Driver was punctual and polite.',
                    'Good experience overall.',
                    'Nice and clean vehicle.',
                    null,
                    null,
                ])->random(),
                'created_at' => $ride->completed_at?->addMinutes(rand(1, 30)),
            ]);

            if (rand(1, 10) <= 7) {
                DB::table('ratings')->insert([
                    'id' => (string) Str::uuid(),
                    'ride_id' => $ride->id,
                    'rated_by_user_id' => $ride->driver_id,
                    'rated_user_id' => $ride->passenger_id,
                    'score' => rand(4, 5),
                    'comment' => null,
                    'created_at' => $ride->completed_at?->addMinutes(rand(1, 60)),
                ]);
            }
        }
    }

    /**
     * @param  array<int, Ride>  $rides
     */
    private function seedDisputes(array $rides): void
    {
        if (DB::table('disputes')->exists()) {
            return;
        }

        $completedRides = array_filter($rides, fn (Ride $r) => $r->status === 'completed');

        if (count($completedRides) < 2) {
            return;
        }

        $disputeRides = array_slice(array_values($completedRides), 0, 2);

        $admin = User::where('email', 'admin@etigo.com')->first();

        $disputes = [
            [
                'ride' => $disputeRides[0],
                'category' => DisputeCategory::FareDispute->value,
                'description' => 'I was charged more than the estimated fare. The driver took a longer route through heavy traffic instead of the shorter route I suggested.',
                'status' => 'resolved',
                'resolution_notes' => 'Fare adjusted. Partial refund of ₦500 issued to passenger. Driver route deviation confirmed via GPS logs.',
            ],
            [
                'ride' => $disputeRides[1],
                'category' => DisputeCategory::VehicleCondition->value,
                'description' => 'The vehicle was not clean and the air conditioning was not working during the ride.',
                'status' => 'open',
                'resolution_notes' => null,
            ],
        ];

        foreach ($disputes as $d) {
            DB::table('disputes')->insert([
                'id' => (string) Str::uuid(),
                'ride_id' => $d['ride']->id,
                'reported_by_user_id' => $d['ride']->passenger_id,
                'category' => $d['category'],
                'description' => $d['description'],
                'status' => $d['status'],
                'resolution_notes' => $d['resolution_notes'],
                'resolved_by_admin_id' => $d['status'] === 'resolved' ? $admin?->id : null,
                'resolved_at' => $d['status'] === 'resolved' ? now()->subDays(1) : null,
                'created_at' => $d['ride']->completed_at?->addHours(rand(1, 12)),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, User>  $passengers
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     */
    private function seedNotifications(array $passengers, array $drivers): void
    {
        $passenger = $passengers['ade'];
        $driverUser = $drivers['bayo']['user'];

        if (Notification::where('user_id', $passenger->id)->exists()) {
            return;
        }

        $notifications = [
            ['user_id' => $passenger->id, 'title' => 'Welcome to E-tiGo!', 'body' => 'Your account is ready. Book your first ride now.', 'type' => 'system', 'is_read' => true, 'read_at' => now()->subDay()],
            ['user_id' => $passenger->id, 'title' => 'Payment method added', 'body' => 'Your Visa card ending in 4242 has been saved.', 'type' => 'payment', 'is_read' => true, 'read_at' => now()->subDays(6)],
            ['user_id' => $passenger->id, 'title' => 'Ride completed', 'body' => 'Your ride from Ikeja to Victoria Island has been completed. Fare: ₦3,500.', 'type' => 'ride', 'is_read' => true, 'read_at' => now()->subDays(7)],
            ['user_id' => $passenger->id, 'title' => 'New promo code!', 'body' => 'Use code WELCOME50 for 50% off your next ride (max ₦2,000 discount).', 'type' => 'promo', 'is_read' => false, 'read_at' => null],
            ['user_id' => $passenger->id, 'title' => 'Ride in progress', 'body' => 'Your ride has started. Share your trip with friends and family for safety.', 'type' => 'ride', 'is_read' => false, 'read_at' => null],
            ['user_id' => $driverUser->id, 'title' => 'Welcome aboard, driver!', 'body' => 'Your account has been approved. You can now go online and accept rides.', 'type' => 'system', 'is_read' => true, 'read_at' => now()->subDays(29)],
            ['user_id' => $driverUser->id, 'title' => 'Weekly earnings summary', 'body' => 'You earned ₦45,200 from 18 rides this week. Keep it up!', 'type' => 'earnings', 'is_read' => false, 'read_at' => null],
            ['user_id' => $driverUser->id, 'title' => 'Ride assigned', 'body' => 'You have been assigned to a ride. Pickup at 12 Marina Road, Lagos Island.', 'type' => 'ride_assigned', 'is_read' => false, 'read_at' => null],
        ];

        foreach ($notifications as $notif) {
            Notification::create($notif + ['created_at' => now()->subHours(rand(1, 168))]);
        }
    }

    /**
     * @param  array<string, User>  $passengers
     */
    private function seedSavedPlaces(array $passengers): void
    {
        if (SavedPlace::exists()) {
            return;
        }

        $places = [
            ['user' => $passengers['ade'], 'type' => 'home', 'name' => 'Home', 'address' => '12 Adekunle Fajuyi Way, Ikeja GRA, Lagos', 'latitude' => 6.5790, 'longitude' => 3.3467],
            ['user' => $passengers['ade'], 'type' => 'work', 'name' => 'Work', 'address' => '15 Broad Street, Lagos Island, Lagos', 'latitude' => 6.4541, 'longitude' => 3.3947],
            ['user' => $passengers['ngozi'], 'type' => 'home', 'name' => 'Home', 'address' => '3 Admiralty Way, Lekki Phase 1, Lagos', 'latitude' => 6.4355, 'longitude' => 3.4167],
            ['user' => $passengers['ngozi'], 'type' => 'work', 'name' => 'Work', 'address' => '24 Marina Road, Lagos Island, Lagos', 'latitude' => 6.4481, 'longitude' => 3.3905],
            ['user' => $passengers['emeka'], 'type' => 'home', 'name' => 'Home', 'address' => '7 Aguiyi Ironsi Street, Maitama, Abuja', 'latitude' => 9.0246, 'longitude' => 7.4939],
            ['user' => $passengers['funmi'], 'type' => 'home', 'name' => 'Home', 'address' => '45 Bode Thomas Street, Surulere, Lagos', 'latitude' => 6.4937, 'longitude' => 3.3574],
            ['user' => $passengers['funmi'], 'type' => 'other', 'name' => 'Gym', 'address' => 'Body Perfect Gym, Ilupeju, Lagos', 'latitude' => 6.5538, 'longitude' => 3.3617],
        ];

        foreach ($places as $place) {
            SavedPlace::create([
                'user_id' => $place['user']->id,
                'type' => $place['type'],
                'name' => $place['name'],
                'address' => $place['address'],
                'latitude' => $place['latitude'],
                'longitude' => $place['longitude'],
            ]);
        }
    }

    private function seedDisputeNotes(): void
    {
        if (DB::table('dispute_notes')->exists()) {
            return;
        }

        $resolvedDispute = DB::table('disputes')->where('status', 'resolved')->first();
        $admin = User::where('email', 'admin@etigo.com')->first();

        if (! $resolvedDispute || ! $admin) {
            return;
        }

        $notes = [
            ['content' => 'Reviewed GPS logs. Driver deviated from suggested route by 3.2 km.', 'created_at' => now()->subDays(2)],
            ['content' => 'Contacted passenger via phone. Confirmed fare discrepancy of ₦500.', 'created_at' => now()->subDays(2)->addHours(2)],
            ['content' => 'Partial refund of ₦500 approved and processed. Case resolved.', 'created_at' => now()->subDays(1)],
        ];

        foreach ($notes as $note) {
            DB::table('dispute_notes')->insert([
                'id' => (string) Str::uuid(),
                'dispute_id' => $resolvedDispute->id,
                'admin_id' => $admin->id,
                'content' => $note['content'],
                'created_at' => $note['created_at'],
                'updated_at' => $note['created_at'],
            ]);
        }
    }

    private function seedSurgeRules(): void
    {
        if (SurgeRule::exists()) {
            return;
        }

        $lagos = City::where('slug', 'lagos')->first();
        $abuja = City::where('slug', 'abuja')->first();
        $admin = User::where('email', 'admin@etigo.com')->first();

        if (! $lagos || ! $admin) {
            return;
        }

        $rules = [
            [
                'city_id' => $lagos->id,
                'vehicle_class_id' => null,
                'name' => 'Lagos Rush Hour Morning',
                'type' => SurgeType::TimeBased->value,
                'multiplier' => 1.50,
                'conditions' => ['days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'start_time' => '07:00', 'end_time' => '09:30'],
                'priority' => 10,
                'is_active' => true,
                'effective_from' => now()->subDays(30),
                'effective_until' => null,
            ],
            [
                'city_id' => $lagos->id,
                'vehicle_class_id' => null,
                'name' => 'Lagos Rush Hour Evening',
                'type' => SurgeType::TimeBased->value,
                'multiplier' => 1.40,
                'conditions' => ['days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'start_time' => '17:00', 'end_time' => '20:00'],
                'priority' => 10,
                'is_active' => true,
                'effective_from' => now()->subDays(30),
                'effective_until' => null,
            ],
            [
                'city_id' => $lagos->id,
                'vehicle_class_id' => null,
                'name' => 'Lagos High Demand Zone',
                'type' => SurgeType::DemandBased->value,
                'multiplier' => 1.75,
                'conditions' => ['min_pending_rides' => 15, 'min_utilization_pct' => 85],
                'priority' => 20,
                'is_active' => true,
                'effective_from' => now()->subDays(14),
                'effective_until' => null,
            ],
        ];

        if ($abuja) {
            $rules[] = [
                'city_id' => $abuja->id,
                'vehicle_class_id' => null,
                'name' => 'Abuja Weekend Surge',
                'type' => SurgeType::TimeBased->value,
                'multiplier' => 1.25,
                'conditions' => ['days' => ['friday', 'saturday', 'sunday'], 'start_time' => '20:00', 'end_time' => '23:59'],
                'priority' => 5,
                'is_active' => true,
                'effective_from' => now()->subDays(7),
                'effective_until' => null,
            ];
        }

        foreach ($rules as $rule) {
            SurgeRule::create($rule + ['created_by_admin_id' => $admin->id]);
        }
    }

    /**
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     */
    private function seedKycVerifications(array $drivers): void
    {
        if (KycVerification::exists()) {
            return;
        }

        foreach ($drivers as $key => $data) {
            $driver = $data['driver'];

            if (! in_array($driver->status, [DriverStatus::Approved, DriverStatus::PendingReview])) {
                continue;
            }

            $isApproved = $driver->status === DriverStatus::Approved;

            KycVerification::create([
                'driver_id' => $driver->id,
                'type' => KycVerificationType::Nin->value,
                'id_number' => '1234567890' . rand(1, 9),
                'provider_reference' => 'QOR_' . Str::random(16),
                'status' => $isApproved ? KycVerificationStatus::Verified->value : KycVerificationStatus::Pending->value,
                'match_data' => $isApproved ? ['name_match' => 95, 'dob_match' => true] : null,
                'verified_at' => $isApproved ? now()->subDays(rand(5, 25)) : null,
                'expires_at' => $isApproved ? now()->addYear() : null,
            ]);

            if ($isApproved) {
                KycVerification::create([
                    'driver_id' => $driver->id,
                    'type' => KycVerificationType::DriversLicense->value,
                    'id_number' => $driver->licence_number,
                    'provider_reference' => 'QOR_' . Str::random(16),
                    'status' => KycVerificationStatus::Verified->value,
                    'match_data' => ['name_match' => 98, 'expiry_valid' => true],
                    'verified_at' => now()->subDays(rand(5, 25)),
                    'expires_at' => now()->addYears(3),
                ]);
            }
        }
    }

    /**
     * @param  array<string, User>  $passengers
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     */
    private function seedGamificationProfiles(array $passengers, array $drivers): void
    {
        if (GamificationProfile::exists()) {
            return;
        }

        $profiles = [
            ['user' => $passengers['ade'], 'carbon' => 12.50, 'points' => 320, 'tier' => 2],
            ['user' => $passengers['ngozi'], 'carbon' => 8.30, 'points' => 180, 'tier' => 1],
            ['user' => $passengers['emeka'], 'carbon' => 15.75, 'points' => 520, 'tier' => 3],
            ['user' => $passengers['funmi'], 'carbon' => 5.20, 'points' => 90, 'tier' => 1],
            ['user' => $passengers['chidi'], 'carbon' => 22.10, 'points' => 650, 'tier' => 3],
            ['user' => $drivers['bayo']['user'], 'carbon' => 45.80, 'points' => 1200, 'tier' => 4],
            ['user' => $drivers['kemi']['user'], 'carbon' => 28.40, 'points' => 780, 'tier' => 3],
            ['user' => $drivers['segun']['user'], 'carbon' => 55.90, 'points' => 1850, 'tier' => 5],
        ];

        foreach ($profiles as $profile) {
            GamificationProfile::create([
                'user_id' => $profile['user']->id,
                'total_carbon_score' => $profile['carbon'],
                'total_ranking_points' => $profile['points'],
                'current_tier' => $profile['tier'],
                'tier_upgraded_at' => $profile['tier'] > 1 ? now()->subDays(rand(5, 30)) : null,
            ]);
        }
    }

    /**
     * @param  array<int, Ride>  $rides
     */
    private function seedTripCarbonScores(array $rides): void
    {
        if (TripCarbonScore::exists()) {
            return;
        }

        foreach ($rides as $ride) {
            if ($ride->status !== 'completed') {
                continue;
            }

            $distanceKm = rand(5, 25) + (rand(0, 99) / 100);
            $baselineEmission = $distanceKm * 0.21;
            $vehicleEmission = $distanceKm * 0.12;

            TripCarbonScore::create([
                'ride_id' => $ride->id,
                'user_id' => $ride->passenger_id,
                'distance_km' => $distanceKm,
                'baseline_emission' => $baselineEmission,
                'vehicle_emission' => $vehicleEmission,
                'co2_saved' => max(0, $baselineEmission - $vehicleEmission),
                'base_points' => (int) ($distanceKm * 2),
                'multiplier_applied' => 1.00,
                'multiplier_reason' => null,
                'final_points' => (int) ($distanceKm * 2),
            ]);
        }
    }

    /**
     * @param  array<string, User>  $passengers
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     */
    private function seedNotificationPreferences(array $passengers, array $drivers): void
    {
        if (NotificationPreference::exists()) {
            return;
        }

        $usersToSeed = [$passengers['ade'], $passengers['ngozi'], $drivers['bayo']['user']];

        foreach ($usersToSeed as $user) {
            foreach (NotificationCategory::cases() as $category) {
                $pushEnabled = true;
                $inAppEnabled = true;

                if ($category === NotificationCategory::Promotions) {
                    $pushEnabled = rand(0, 1) === 1;
                }

                NotificationPreference::create([
                    'user_id' => $user->id,
                    'category' => $category->value,
                    'push_enabled' => $pushEnabled,
                    'in_app_enabled' => $inAppEnabled,
                ]);
            }
        }
    }

    private function seedAppSettings(): void
    {
        if (AppSetting::exists()) {
            return;
        }

        $settings = [
            ['key' => 'wallet.min_topup', 'value' => '50000', 'group' => 'wallet'],
            ['key' => 'wallet.max_balance', 'value' => '50000000', 'group' => 'wallet'],
            ['key' => 'wallet.daily_topup_cap', 'value' => '10000000', 'group' => 'wallet'],
            ['key' => 'wallet.min_payout', 'value' => '100000', 'group' => 'wallet'],
            ['key' => 'wallet.settlement_delay', 'value' => 'instant', 'group' => 'wallet'],
            ['key' => 'wallet.max_negative_balance', 'value' => '-500000', 'group' => 'wallet'],
            ['key' => 'wallet.hold_expiry_hours', 'value' => '4', 'group' => 'wallet'],
            ['key' => 'wallet.abandoned_topup_minutes', 'value' => '30', 'group' => 'wallet'],
            ['key' => 'ride.max_search_radius_km', 'value' => '10', 'group' => 'ride'],
            ['key' => 'ride.max_search_attempts', 'value' => '3', 'group' => 'ride'],
            ['key' => 'ride.driver_arrival_timeout_minutes', 'value' => '15', 'group' => 'ride'],
            ['key' => 'ride.free_cancellation_window_seconds', 'value' => '60', 'group' => 'ride'],
            ['key' => 'support.email', 'value' => 'support@etigo.com', 'group' => 'support'],
            ['key' => 'support.phone', 'value' => '+2349000000000', 'group' => 'support'],
            ['key' => 'general.maintenance_mode', 'value' => 'false', 'group' => 'general'],
            ['key' => 'general.min_app_version_ios', 'value' => '1.0.0', 'group' => 'general'],
            ['key' => 'general.min_app_version_android', 'value' => '1.0.0', 'group' => 'general'],
            ['key' => 'tip.presets', 'value' => json_encode([100, 200, 500, 1000]), 'group' => 'tip'],
            ['key' => 'tip.min_amount', 'value' => '50', 'group' => 'tip'],
            ['key' => 'tip.max_amount', 'value' => '50000', 'group' => 'tip'],
        ];

        foreach ($settings as $setting) {
            AppSetting::create($setting);
        }
    }

    /**
     * @param  array<string, User>  $passengers
     * @param  array<int, Ride>  $rides
     */
    private function seedPromoRedemptions(array $passengers, array $rides): void
    {
        if (DB::table('promo_redemptions')->exists()) {
            return;
        }

        $welcomePromo = DB::table('promo_codes')->where('code', 'WELCOME50')->first();
        $ridePromo = DB::table('promo_codes')->where('code', 'RIDE500')->first();

        $completedRides = array_values(array_filter($rides, fn (Ride $r) => $r->status === 'completed'));

        if (! $welcomePromo || count($completedRides) < 2) {
            return;
        }

        DB::table('promo_redemptions')->insert([
            'id' => (string) Str::uuid(),
            'promo_code_id' => $welcomePromo->id,
            'user_id' => $completedRides[0]->passenger_id,
            'ride_id' => $completedRides[0]->id,
            'discount_amount' => min(($completedRides[0]->final_fare_amount ?? 0) * 0.50, 2000),
            'redeemed_at' => $completedRides[0]->completed_at,
            'created_at' => $completedRides[0]->completed_at,
        ]);

        if ($ridePromo && count($completedRides) >= 3) {
            DB::table('promo_redemptions')->insert([
                'id' => (string) Str::uuid(),
                'promo_code_id' => $ridePromo->id,
                'user_id' => $completedRides[2]->passenger_id,
                'ride_id' => $completedRides[2]->id,
                'discount_amount' => 500.00,
                'redeemed_at' => $completedRides[2]->completed_at,
                'created_at' => $completedRides[2]->completed_at,
            ]);
        }
    }

    /**
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     */
    private function seedBankAccounts(array $drivers): void
    {
        if (BankAccount::exists()) {
            return;
        }

        $banks = [
            ['code' => '058', 'name' => 'Guaranty Trust Bank'],
            ['code' => '033', 'name' => 'United Bank for Africa'],
            ['code' => '044', 'name' => 'Access Bank'],
            ['code' => '011', 'name' => 'First Bank of Nigeria'],
            ['code' => '057', 'name' => 'Zenith Bank'],
        ];

        $i = 0;
        foreach ($drivers as $data) {
            if (! in_array($data['driver']->status, [DriverStatus::Approved, DriverStatus::Suspended])) {
                continue;
            }

            $bank = $banks[$i % count($banks)];
            BankAccount::create([
                'driver_id' => $data['driver']->id,
                'bank_code' => $bank['code'],
                'account_number' => str_pad((string) rand(1000000000, 9999999999), 10, '0'),
                'account_name' => $data['user']->first_name . ' ' . $data['user']->last_name,
                'is_verified' => $data['driver']->status === DriverStatus::Approved,
                'is_primary' => true,
            ]);
            $i++;
        }
    }

    /**
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     */
    private function seedEvCharging(array $drivers): void
    {
        if (EvChargingStation::exists()) {
            return;
        }

        $lagos = City::where('slug', 'lagos')->first();

        if (! $lagos) {
            return;
        }

        $stations = [
            [
                'name' => 'Ikeja EV Hub',
                'city_id' => $lagos->id,
                'lat' => 6.6018,
                'lng' => 3.3515,
                'address' => '22 Allen Avenue, Ikeja, Lagos',
                'total_stalls' => 6,
                'status' => EvStationStatus::Active->value,
            ],
            [
                'name' => 'Lekki Green Charge',
                'city_id' => $lagos->id,
                'lat' => 6.4355,
                'lng' => 3.4539,
                'address' => '5 Admiralty Road, Lekki Phase 1, Lagos',
                'total_stalls' => 4,
                'status' => EvStationStatus::Active->value,
            ],
            [
                'name' => 'VI Power Station',
                'city_id' => $lagos->id,
                'lat' => 6.4281,
                'lng' => 3.4219,
                'address' => '10 Adeola Odeku Street, Victoria Island, Lagos',
                'total_stalls' => 3,
                'status' => EvStationStatus::Maintenance->value,
            ],
        ];

        foreach ($stations as $stationData) {
            $station = EvChargingStation::create($stationData);

            for ($s = 1; $s <= $station->total_stalls; $s++) {
                $status = EvStallStatus::Available;
                if ($station->status === EvStationStatus::Maintenance->value) {
                    $status = EvStallStatus::OutOfService;
                } elseif ($s === 1 && $station->name === 'Ikeja EV Hub') {
                    $status = EvStallStatus::Occupied;
                }

                EvChargingStall::create([
                    'station_id' => $station->id,
                    'stall_number' => $s,
                    'status' => $status->value,
                ]);
            }
        }

        $firstStation = EvChargingStation::where('name', 'Ikeja EV Hub')->first();
        $firstStall = $firstStation?->stalls()->first();
        $bayoUser = $drivers['bayo']['user'] ?? null;

        if ($firstStall && $bayoUser) {
            EvReservation::create([
                'stall_id' => $firstStall->id,
                'station_id' => $firstStation->id,
                'driver_id' => $bayoUser->id,
                'status' => EvReservationStatus::Completed->value,
                'fee_amount' => 750.00,
                'reserved_at' => now()->subDays(2),
                'activated_at' => now()->subDays(2)->addMinutes(10),
                'completed_at' => now()->subDays(2)->addMinutes(55),
            ]);

            $secondStall = $firstStation->stalls()->where('stall_number', 2)->first();
            if ($secondStall) {
                EvReservation::create([
                    'stall_id' => $secondStall->id,
                    'station_id' => $firstStation->id,
                    'driver_id' => $bayoUser->id,
                    'status' => EvReservationStatus::Reserved->value,
                    'fee_amount' => 750.00,
                    'reserved_at' => now()->addHours(2),
                ]);
            }
        }
    }

    /**
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     */
    private function seedFleetAgreements(array $drivers): void
    {
        if (FleetAgreement::exists()) {
            return;
        }

        $tundeDriver = $drivers['tunde']['driver'] ?? null;
        $tundeVehicle = $tundeDriver?->vehicle;
        $admin = User::where('email', 'admin@etigo.com')->first();

        if (! $tundeDriver || ! $tundeVehicle || ! $admin) {
            return;
        }

        $agreement = FleetAgreement::create([
            'driver_id' => $tundeDriver->id,
            'vehicle_id' => $tundeVehicle->id,
            'daily_remittance_target' => 8000.00,
            'total_vehicle_cost' => 4500000.00,
            'total_remitted' => 480000.00,
            'agreement_start_date' => now()->subDays(60),
            'status' => FleetAgreementStatus::Active->value,
            'created_by_admin_id' => $admin->id,
        ]);

        for ($day = 7; $day >= 1; $day--) {
            $remitted = rand(5000, 12000);
            $target = 8000;

            DailyRemittance::create([
                'agreement_id' => $agreement->id,
                'driver_id' => $tundeDriver->id,
                'date' => now()->subDays($day)->toDateString(),
                'target_amount' => $target,
                'remitted_amount' => $remitted,
                'shortfall_amount' => max(0, $target - $remitted),
                'target_met_at' => $remitted >= $target ? now()->subDays($day)->setHour(rand(18, 22)) : null,
                'ride_count' => rand(8, 18),
                'total_fares' => $remitted + rand(2000, 5000),
                'driver_earnings' => rand(2000, 5000),
                'settled' => true,
            ]);
        }
    }

    /**
     * @param  array<int, Ride>  $rides
     */
    private function seedSosIncident(array $rides): void
    {
        if (DB::table('sos_incidents')->exists()) {
            return;
        }

        $inProgressRide = collect($rides)->first(fn (Ride $r) => $r->status === 'in_progress');
        $admin = User::where('email', 'safety@etigo.com')->orWhere('email', 'admin@etigo.com')->first();

        if (! $inProgressRide) {
            return;
        }

        $incidentId = (string) Str::uuid();

        DB::table('sos_incidents')->insert([
            'id' => $incidentId,
            'ride_id' => $inProgressRide->id,
            'triggered_by_user_id' => $inProgressRide->passenger_id,
            'trigger_type' => SosTriggerType::Passenger->value,
            'status' => 'resolved',
            'gps_lat' => $inProgressRide->pickup_lat,
            'gps_lng' => $inProgressRide->pickup_lng,
            'operator_id' => $admin?->id,
            'operator_notes' => 'False alarm confirmed via phone check-in. Passenger accidentally triggered SOS.',
            'resolved_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(12),
        ]);

        $events = [
            ['event_type' => 'sos_triggered', 'actor_id' => $inProgressRide->passenger_id, 'metadata' => json_encode(['source' => 'app_button']), 'created_at' => now()->subMinutes(12)],
            ['event_type' => 'check_in_sent', 'actor_id' => null, 'metadata' => json_encode(['channel' => 'push']), 'created_at' => now()->subMinutes(11)],
            ['event_type' => 'operator_assigned', 'actor_id' => $admin?->id, 'metadata' => json_encode([]), 'created_at' => now()->subMinutes(8)],
            ['event_type' => 'resolved', 'actor_id' => $admin?->id, 'metadata' => json_encode(['resolution' => 'false_alarm']), 'created_at' => now()->subMinutes(5)],
        ];

        foreach ($events as $event) {
            DB::table('sos_event_log')->insert([
                'id' => (string) Str::uuid(),
                'incident_id' => $incidentId,
                'event_type' => $event['event_type'],
                'actor_id' => $event['actor_id'],
                'metadata' => $event['metadata'],
                'created_at' => $event['created_at'],
            ]);
        }
    }

    /**
     * @param  array<string, User>  $passengers
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     */
    private function seedWalletAccounts(array $passengers, array $drivers): void
    {
        if (Account::where('type', AccountType::PassengerWallet)->exists()) {
            return;
        }

        $walletBalances = [
            'ade' => 1500000,
            'ngozi' => 750000,
            'emeka' => 3200000,
            'funmi' => 250000,
            'chidi' => 0,
        ];

        foreach ($passengers as $key => $passenger) {
            Account::create([
                'owner_type' => User::class,
                'owner_id' => $passenger->id,
                'type' => AccountType::PassengerWallet,
                'currency' => 'NGN',
                'status' => AccountStatus::Active,
                'balance' => $walletBalances[$key] ?? 0,
                'balance_version' => 0,
            ]);
        }

        foreach ($drivers as $key => $data) {
            if (! in_array($data['driver']->status, [DriverStatus::Approved, DriverStatus::Suspended])) {
                continue;
            }

            Account::create([
                'owner_type' => User::class,
                'owner_id' => $data['user']->id,
                'type' => AccountType::DriverEarningsPending,
                'currency' => 'NGN',
                'status' => AccountStatus::Active,
                'balance' => 0,
                'balance_version' => 0,
            ]);

            $availableBalance = match ($key) {
                'bayo' => 850000,
                'kemi' => 420000,
                'segun' => 1200000,
                'tunde' => -150000,
                default => 0,
            };

            Account::create([
                'owner_type' => User::class,
                'owner_id' => $data['user']->id,
                'type' => AccountType::DriverEarningsAvailable,
                'currency' => 'NGN',
                'status' => AccountStatus::Active,
                'balance' => $availableBalance,
                'balance_version' => 0,
            ]);
        }
    }

    /**
     * @param  array<int, Ride>  $rides
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     */
    private function seedOfflineTripFlags(array $rides, array $drivers): void
    {
        if (OfflineTripFlag::exists()) {
            return;
        }

        $cancelledRide = collect($rides)->first(fn (Ride $r) => $r->status === 'cancelled');
        $completedRides = collect($rides)->filter(fn (Ride $r) => $r->status === 'completed')->values();
        $admin = User::where('email', 'admin@etigo.com')->first();

        if (! $cancelledRide || $completedRides->count() < 3) {
            return;
        }

        OfflineTripFlag::create([
            'ride_id' => $cancelledRide->id,
            'driver_id' => $cancelledRide->driver_id,
            'passenger_id' => $cancelledRide->passenger_id,
            'detection_data' => [
                'collocation_points' => 5,
                'route_match_pct' => 72.5,
                'monitoring_duration_minutes' => 15,
                'max_distance_from_route_m' => 85,
            ],
            'sanction_tier' => SanctionTier::Warning,
            'sanction_action' => SanctionTier::Warning->action(),
            'is_disputed' => false,
            'flagged_at' => $cancelledRide->created_at->addMinutes(35),
        ]);

        OfflineTripFlag::create([
            'ride_id' => $completedRides[1]->id,
            'driver_id' => $completedRides[1]->driver_id,
            'passenger_id' => $completedRides[1]->passenger_id,
            'detection_data' => [
                'collocation_points' => 8,
                'route_match_pct' => 88.3,
                'monitoring_duration_minutes' => 15,
                'max_distance_from_route_m' => 42,
            ],
            'sanction_tier' => SanctionTier::Suspension,
            'sanction_action' => SanctionTier::Suspension->action(),
            'is_disputed' => true,
            'dispute_notes' => 'The passenger asked me to pull over briefly to pick up a package. We continued the trip normally after.',
            'dispute_outcome' => DisputeOutcome::Overturned,
            'dispute_resolved_by_admin_id' => $admin?->id,
            'flagged_at' => now()->subDays(20),
            'resolved_at' => now()->subDays(18),
        ]);

        OfflineTripFlag::create([
            'ride_id' => $completedRides[2]->id,
            'driver_id' => $completedRides[2]->driver_id,
            'passenger_id' => $completedRides[2]->passenger_id,
            'detection_data' => [
                'collocation_points' => 6,
                'route_match_pct' => 65.1,
                'monitoring_duration_minutes' => 15,
                'max_distance_from_route_m' => 120,
            ],
            'sanction_tier' => SanctionTier::Warning,
            'sanction_action' => SanctionTier::Warning->action(),
            'is_disputed' => true,
            'dispute_notes' => 'This was a legitimate ride that I completed on the app. The detection must be a false positive.',
            'dispute_outcome' => DisputeOutcome::Pending,
            'flagged_at' => now()->subDays(2),
        ]);
    }
}
