<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Ride;
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
                'gateway_token' => 'FLW_'.Str::random(24),
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
                'status' => 'completed', 'fare' => 3500.00, 'payment_status' => 'paid',
                'created' => now()->subDays(7), 'started' => now()->subDays(7)->addMinutes(5), 'completed' => now()->subDays(7)->addMinutes(35),
            ],
            [
                'passenger' => $passengers['ngozi'], 'driver' => $drivers['kemi'],
                'city' => $lagos, 'class' => $comfortClass, 'route' => $lagosRoutes[1],
                'status' => 'completed', 'fare' => 5200.00, 'payment_status' => 'paid',
                'created' => now()->subDays(5), 'started' => now()->subDays(5)->addMinutes(8), 'completed' => now()->subDays(5)->addMinutes(50),
            ],
            [
                'passenger' => $passengers['emeka'], 'driver' => $drivers['segun'],
                'city' => $abuja, 'class' => $premiumClass, 'route' => $abujaRoutes[0],
                'status' => 'completed', 'fare' => 8500.00, 'payment_status' => 'paid',
                'created' => now()->subDays(3), 'started' => now()->subDays(3)->addMinutes(4), 'completed' => now()->subDays(3)->addMinutes(40),
            ],
            [
                'passenger' => $passengers['ade'], 'driver' => $drivers['bayo'],
                'city' => $lagos, 'class' => $economyClass, 'route' => $lagosRoutes[2],
                'status' => 'completed', 'fare' => 2800.00, 'payment_status' => 'paid',
                'created' => now()->subDays(2), 'started' => now()->subDays(2)->addMinutes(6), 'completed' => now()->subDays(2)->addMinutes(25),
            ],
            [
                'passenger' => $passengers['funmi'], 'driver' => $drivers['kemi'],
                'city' => $lagos, 'class' => $comfortClass, 'route' => $lagosRoutes[3],
                'status' => 'completed', 'fare' => 4100.00, 'payment_status' => 'paid',
                'created' => now()->subDays(1), 'started' => now()->subDays(1)->addMinutes(7), 'completed' => now()->subDays(1)->addMinutes(30),
            ],
            [
                'passenger' => $passengers['chidi'], 'driver' => $drivers['segun'],
                'city' => $abuja, 'class' => $premiumClass, 'route' => $abujaRoutes[1],
                'status' => 'completed', 'fare' => 6000.00, 'payment_status' => 'paid',
                'created' => now()->subDays(1), 'started' => now()->subDays(1)->addMinutes(3), 'completed' => now()->subDays(1)->addMinutes(20),
            ],
            [
                'passenger' => $passengers['ngozi'], 'driver' => $drivers['bayo'],
                'city' => $lagos, 'class' => $economyClass, 'route' => $lagosRoutes[4],
                'status' => 'cancelled', 'fare' => null, 'payment_status' => 'cancelled',
                'created' => now()->subDays(4), 'started' => null, 'completed' => null,
                'cancelled_by' => $passengers['ngozi']->id, 'cancellation_reason' => 'Driver was taking too long to arrive.',
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
                'payment_method' => 'card',
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
                Payment::create([
                    'ride_id' => $ride->id,
                    'amount' => $data['fare'],
                    'currency' => 'NGN',
                    'method' => 'card',
                    'gateway_transaction_id' => 'FLW_TXN_'.Str::random(16),
                    'gateway_payment_method_id' => 'FLW_PM_'.Str::random(12),
                    'tip_amount' => rand(0, 3) === 0 ? rand(2, 10) * 100 : 0,
                    'status' => 'successful',
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
                'category' => 'fare_dispute',
                'description' => 'I was charged more than the estimated fare. The driver took a longer route through heavy traffic instead of the shorter route I suggested.',
                'status' => 'resolved',
                'resolution_notes' => 'Fare adjusted. Partial refund of ₦500 issued to passenger. Driver route deviation confirmed via GPS logs.',
            ],
            [
                'ride' => $disputeRides[1],
                'category' => 'service_quality',
                'description' => 'The vehicle was not clean and the air conditioning was not working during the ride.',
                'status' => 'open',
                'resolution_notes' => null,
            ],
        ];

        foreach ($disputes as $d) {
            DB::table('disputes')->insert([
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
            ['user_id' => $driverUser->id, 'title' => 'New ride request', 'body' => 'A passenger nearby is requesting a ride. Go online to accept.', 'type' => 'ride', 'is_read' => false, 'read_at' => null],
        ];

        foreach ($notifications as $notif) {
            Notification::create($notif + ['created_at' => now()->subHours(rand(1, 168))]);
        }
    }
}
