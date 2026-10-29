<?php

namespace Database\Seeders;

use App\Enums\CancellationReason;
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
use App\Enums\RideStatus;
use App\Enums\SosTriggerType;
use App\Enums\SurgeType;
use App\Enums\UserType;
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
use App\Models\Ride;
use App\Models\SavedPlace;
use App\Models\SurgeRule;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\WalletPaymentService;
use Database\Seeders\Concerns\SeedsDemoActivity;
use Database\Seeders\Data\NigerianCities;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    use SeedsDemoActivity;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping DemoSeeder in production.');

            return;
        }

        $password = Hash::make('Qwer!234');

        $this->callOnce([ReferenceDataSeeder::class, AdminSeeder::class, CitySeeder::class, PricingConfigSeeder::class]);
        $passengers = $this->seedPassengers($password);
        $drivers = $this->seedDrivers($password);
        $this->seedPaymentMethods($passengers);
        $this->seedWallets($passengers);
        $this->seedPromoCodes();
        $rides = $this->seedRides($passengers, $drivers);
        $this->seedNotifications($passengers, $drivers);
        $this->seedSavedPlaces($passengers);
        $this->seedSurgeRules();
        $this->seedKycVerifications($drivers);
        $this->seedGamificationProfiles($passengers, $drivers);
        $this->seedNotificationPreferences($passengers, $drivers);
        $this->seedAppSettings();
        $this->seedPromoRedemptions($passengers, $rides);
        $this->seedBankAccounts($drivers);
        $this->seedEvCharging($drivers);
        $this->seedFleetAgreements($drivers);
        $this->seedSosIncident($rides);
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
                    'is_active' => true,
                ],
            );

            // Not mass assignable on User.
            $result[$key]->phone_verified_at ??= now();
            $result[$key]->saveQuietly();
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
                'city' => 'lagos',
                'user' => ['first_name' => 'Bayo', 'last_name' => 'Akinola', 'phone' => '+2348200000001', 'email' => 'bayo@demo.etigo.com'],
                'driver' => ['licence_number' => 'LAG48213AB25', 'status' => DriverStatus::Approved, 'approved_at' => now()->subDays(30), 'is_online' => true],
                'vehicle' => ['make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'White', 'plate_number' => 'KJA-482AB', 'year' => 2022, 'fuel_type' => 'petrol', 'vehicle_class_id' => $economyClass?->id],
            ],
            'kemi' => [
                'city' => 'lagos',
                'user' => ['first_name' => 'Kemi', 'last_name' => 'Bakare', 'phone' => '+2348200000002', 'email' => 'kemi@demo.etigo.com'],
                'driver' => ['licence_number' => 'LAG73590KB18', 'status' => DriverStatus::Approved, 'approved_at' => now()->subDays(15), 'is_online' => false],
                'vehicle' => ['make' => 'Honda', 'model' => 'Accord', 'colour' => 'Black', 'plate_number' => 'EKY-317CD', 'year' => 2023, 'fuel_type' => 'hybrid', 'vehicle_class_id' => $comfortClass?->id],
            ],
            'segun' => [
                'city' => 'abuja',
                'user' => ['first_name' => 'Segun', 'last_name' => 'Obaseki', 'phone' => '+2348200000003', 'email' => 'segun@demo.etigo.com'],
                'driver' => ['licence_number' => 'FCT20871SO27', 'status' => DriverStatus::Approved, 'approved_at' => now()->subDays(45), 'is_online' => true],
                'vehicle' => ['make' => 'Mercedes-Benz', 'model' => 'EQE', 'colour' => 'Silver', 'plate_number' => 'ABC-905EF', 'year' => 2024, 'fuel_type' => 'electric', 'vehicle_class_id' => $premiumClass?->id],
            ],
            'amara' => [
                'city' => 'abuja',
                'user' => ['first_name' => 'Amara', 'last_name' => 'Nnamdi', 'phone' => '+2348200000004', 'email' => 'amara@demo.etigo.com'],
                'driver' => ['licence_number' => 'FCT51642AN29', 'status' => DriverStatus::PendingReview, 'is_online' => false],
                'vehicle' => ['make' => 'Toyota', 'model' => 'Highlander', 'colour' => 'Blue', 'plate_number' => 'BWR-224GH', 'year' => 2021, 'fuel_type' => 'petrol', 'vehicle_class_id' => $suvClass?->id],
            ],
            'tunde' => [
                'city' => 'lagos',
                'user' => ['first_name' => 'Tunde', 'last_name' => 'Fashola', 'phone' => '+2348200000005', 'email' => 'tunde@demo.etigo.com'],
                'driver' => ['licence_number' => 'LAG66127TF16', 'status' => DriverStatus::Suspended, 'approved_at' => now()->subDays(60), 'suspended_at' => now()->subDays(5), 'is_online' => false],
                'vehicle' => ['make' => 'Nissan', 'model' => 'Sentra', 'colour' => 'Red', 'plate_number' => 'AAA-671IJ', 'year' => 2019, 'fuel_type' => 'cng', 'vehicle_class_id' => $economyClass?->id],
            ],
            'ify' => [
                'city' => 'port-harcourt',
                'user' => ['first_name' => 'Ify', 'last_name' => 'Okoro', 'phone' => '+2348200000006', 'email' => 'ify@demo.etigo.com'],
                'driver' => ['licence_number' => 'RIV30915IO28', 'status' => DriverStatus::Rejected, 'rejection_reason' => 'Documents are blurry and unreadable.', 'is_online' => false],
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
                    'is_active' => true,
                ],
            );
            $user->phone_verified_at ??= now();
            $user->saveQuietly();

            $driver = Driver::firstOrCreate(
                ['user_id' => $user->id],
                $data['driver'] + ['city_id' => City::where('slug', $data['city'])->value('id')],
            );

            if ($driver->city_id === null) {
                $driver->update(['city_id' => City::where('slug', $data['city'])->value('id')]);
            }

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

    /**
     * Wallet money comes from top-ups posted through the ledger; drivers'
     * balances come from settling their trips (seedRides).
     *
     * @param  array<string, User>  $passengers
     */
    private function seedWallets(array $passengers): void
    {
        $topUps = ['ade' => 4_000_000, 'ngozi' => 750_000, 'emeka' => 3_200_000, 'funmi' => 250_000];

        foreach ($topUps as $key => $kobo) {
            if ($this->walletBalance($passengers[$key]) === 0) {
                $this->topUp($passengers[$key], $kobo, now()->subDays(10)->setTime(19, 30));
            }
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
     * The demo accounts' trips, priced from the city's fares and settled
     * through the ledger, plus Ade's trip in progress now.
     *
     * @param  array<string, User>  $passengers
     * @param  array<string, array{user: User, driver: Driver}>  $drivers
     * @return array<int, Ride>
     */
    private function seedRides(array $passengers, array $drivers): array
    {
        if (Ride::whereIn('passenger_id', collect($passengers)->pluck('id'))->exists()) {
            return Ride::whereIn('passenger_id', collect($passengers)->pluck('id'))->get()->all();
        }

        $lagos = City::where('slug', 'lagos')->first();
        $abuja = City::where('slug', 'abuja')->first();
        $classes = VehicleClass::all()->keyBy('name');

        if (! $lagos || ! $abuja || ! $classes->has('economy')) {
            return [];
        }

        $place = fn (string $slug, string $label) => collect(NigerianCities::find($slug)['landmarks'])
            ->first(fn (array $landmark) => str_starts_with($landmark[2], $label));

        $trips = [
            ['ade', 'bayo', 'lagos', 'economy', ['lagos', 'Ikeja City Mall'], ['lagos', 'Eko Hotel'], 7, 'completed', PaymentMethod::Card],
            ['ngozi', 'kemi', 'lagos', 'comfort', ['lagos', 'Admiralty Way'], ['lagos', 'Murtala Muhammed'], 5, 'completed', PaymentMethod::Card],
            ['emeka', 'segun', 'abuja', 'premium', ['abuja', 'Aminu Kano Crescent'], ['abuja', 'Nnamdi Azikiwe'], 3, 'completed', PaymentMethod::Card],
            ['ade', 'bayo', 'lagos', 'economy', ['lagos', 'University of Lagos'], ['lagos', 'Allen Avenue'], 2, 'completed', PaymentMethod::Wallet],
            ['funmi', 'kemi', 'lagos', 'comfort', ['lagos', 'Bode Thomas'], ['lagos', 'Bourdillon Road'], 1, 'completed', PaymentMethod::Card],
            ['chidi', 'segun', 'abuja', 'premium', ['abuja', 'Area 11'], ['abuja', 'Transcorp Hilton'], 1, 'completed', PaymentMethod::Cash],
            ['ngozi', 'bayo', 'lagos', 'economy', ['lagos', 'Computer Village'], ['lagos', 'Admiralty Way'], 4, 'cancelled', PaymentMethod::Card],
            ['funmi', 'bayo', 'lagos', 'economy', ['lagos', 'University of Lagos'], ['lagos', 'Herbert Macaulay'], 3, 'completed', PaymentMethod::Cash],
            ['ngozi', 'kemi', 'lagos', 'comfort', ['lagos', 'Bode Thomas'], ['lagos', 'The Palms'], 2, 'completed', PaymentMethod::Wallet],
        ];

        $rides = [];

        foreach ($trips as [$passenger, $driver, $citySlug, $class, $from, $to, $daysAgo, $status, $payment]) {
            $rides[] = $this->trip([
                'city' => $citySlug === 'lagos' ? $lagos : $abuja,
                'tier' => 'A',
                'class' => $classes[$class],
                'passenger' => $passengers[$passenger],
                'driver' => $drivers[$driver]['user'],
                'from' => $place(...$from),
                'to' => $place(...$to),
                'requested_at' => now()->subDays($daysAgo)->setTime(8 + $daysAgo, 15),
                'status' => $status,
                'payment' => $payment,
                'cancelled_by' => $status === 'cancelled' ? 'passenger' : null,
                'cancellation_reason' => $status === 'cancelled' ? CancellationReason::DriverTooFar->value : null,
                'tip_kobo' => $passenger === 'emeka' ? 50_000 : 0,
                'rating' => [$daysAgo % 2 === 0 ? 4 : 5, $daysAgo % 2 === 0 ? 'Good ride, a bit of traffic on Third Mainland Bridge.' : 'Driver was punctual and polite.'],
                'driver_rating' => 5,
            ]);
        }

        // Ade is on a trip with Bayo right now, paid from the wallet, so the
        // fare is held (the SOS demo uses this trip).
        $quote = $this->quote($lagos, $classes['economy'], $place('lagos', 'Ikeja City Mall'), $place('lagos', 'Eko Hotel'), 'A');
        [$from, $to] = [$place('lagos', 'Ikeja City Mall'), $place('lagos', 'Eko Hotel')];

        $inProgress = Ride::create([
            'city_id' => $lagos->id,
            'vehicle_class_id' => $classes['economy']->id,
            'passenger_id' => $passengers['ade']->id,
            'driver_id' => $drivers['bayo']['user']->id,
            'pickup_lat' => $from[0],
            'pickup_lng' => $from[1],
            'pickup_address' => $from[2],
            'destination_lat' => $to[0],
            'destination_lng' => $to[1],
            'destination_address' => $to[2],
            'status' => RideStatus::InProgress,
            'pin_code' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            'share_token' => Str::random(32),
            'fare_estimate_amount' => $quote['fare'],
            'fare_currency' => 'NGN',
            'pricing_snapshot' => $quote['snapshot'],
            'payment_method' => PaymentMethod::Wallet,
            'payment_status' => PaymentStatus::Pending->value,
            'matched_at' => now()->subMinutes(14),
            'started_at' => now()->subMinutes(10),
        ]);

        $this->at(now()->subMinutes(15), fn () => app(WalletPaymentService::class)->placeHold($passengers['ade'], $inProgress, (int) round($quote['fare'] * 100)));
        $rides[] = $inProgress;

        return array_values(array_filter($rides));
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
                'conditions' => ['days_of_week' => [1, 2, 3, 4, 5], 'start_time' => '07:00', 'end_time' => '09:30'],
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
                'conditions' => ['days_of_week' => [1, 2, 3, 4, 5], 'start_time' => '17:00', 'end_time' => '20:00'],
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
                'conditions' => ['min_demand_supply_ratio' => 2.0, 'min_requests' => 15],
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
                // Ends at midnight: an end before the start runs past midnight.
                'conditions' => ['days_of_week' => [5, 6, 7], 'start_time' => '20:00', 'end_time' => '00:00'],
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
                'id_number' => '1234567890'.rand(1, 9),
                'provider_reference' => 'QOR_'.Str::random(16),
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
                    'provider_reference' => 'QOR_'.Str::random(16),
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
            GamificationProfile::updateOrCreate(['user_id' => $profile['user']->id], [
                'total_carbon_score' => $profile['carbon'],
                'total_ranking_points' => $profile['points'],
                'current_tier' => $profile['tier'],
                'tier_upgraded_at' => $profile['tier'] > 1 ? now()->subDays(rand(5, 30)) : null,
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

        $completedRides = array_values(array_filter($rides, fn (Ride $r) => $r->status === RideStatus::Completed));

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
                'account_name' => $data['user']->first_name.' '.$data['user']->last_name,
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

        $inProgressRide = collect($rides)->first(fn (Ride $r) => $r->status === RideStatus::InProgress);
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
}
