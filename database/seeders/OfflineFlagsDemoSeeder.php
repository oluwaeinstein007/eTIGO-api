<?php

namespace Database\Seeders;

use App\Console\Commands\SimulateDemoDriversCommand;
use App\Enums\CancellationReason;
use App\Enums\DisputeOutcome;
use App\Enums\DriverStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Enums\SanctionTier;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use App\Models\RideStateTransition;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\SanctionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test data for the admin Offline Trip Flags (compliance) screens: six
 * drivers whose flags cover every sanction level and every status (flagged,
 * disputed, upheld, overturned), each on a cancelled ride with detection
 * evidence and a review history.
 *
 * The drivers are separate from the Live Monitoring demo drivers, stay
 * offline and are never auto-matched, so reviewing, escalating or confirming
 * a deactivation here changes nothing else. Re-running it deletes the
 * previous demo flags and their rides and seeds them afresh, relative to now.
 *
 * php artisan db:seed --class=OfflineFlagsDemoSeeder
 */
class OfflineFlagsDemoSeeder extends Seeder
{
    private const PASSWORD = 'Qwer!234';

    /**
     * Marks the seeder's rides (in their state transitions' metadata) so a
     * re-run deletes only those.
     */
    private const SEEDER_TAG = 'OfflineFlagsDemoSeeder';

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

    private ?User $operationsAdmin = null;

    private ?User $safetyAdmin = null;

    /**
     * Flags queued by flag(), created in date order so their reference
     * numbers (and their rides') run with the dates.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $queued = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping OfflineFlagsDemoSeeder in production.');

            return;
        }

        $lagos = City::where('slug', 'lagos')->first();
        $economy = VehicleClass::where('name', 'economy')->first();
        $comfort = VehicleClass::where('name', 'comfort')->first();

        if (! $lagos || ! $economy || ! $comfort) {
            $this->command?->error('Lagos and the economy and comfort vehicle classes are required. Run DemoSeeder first.');

            return;
        }

        $this->operationsAdmin = $this->admin('ops@etigo.com');
        $this->safetyAdmin = $this->admin('safety@etigo.com');

        DB::transaction(function () use ($lagos, $economy, $comfort) {
            $drivers = $this->seedDrivers($lagos, $economy, $comfort);
            $this->resetPreviousFlags($drivers);

            $passengers = $this->seedPassengers();

            $this->seedFlags($lagos, $economy, $comfort, $drivers, $passengers);
        });

        $this->command?->info('Offline trip flag demo data ready: 12 flags for 6 drivers (GET /admin/offline-flags).');
    }

    private function email(string $name): string
    {
        return $name.'@'.SimulateDemoDriversCommand::emailDomain();
    }

    private function admin(string $email): ?User
    {
        return User::where('email', $email)->where('type', UserType::Admin)->first()
            ?? User::where('type', UserType::Admin)->orderBy('created_at')->first();
    }

    /**
     * @param  array<string, Driver>  $drivers
     */
    private function resetPreviousFlags(array $drivers): void
    {
        // Only the rides this seeder made; anything else the demo drivers
        // were given (say, by manual assignment) is left alone.
        $rideIds = RideStateTransition::where('metadata->seeder', self::SEEDER_TAG)
            ->whereHas('ride', fn ($query) => $query->whereIn('driver_id', collect($drivers)->pluck('user_id')))
            ->distinct()
            ->pluck('ride_id');
        $flagIds = OfflineTripFlag::whereIn('ride_id', $rideIds)->pluck('id');

        AuditLog::where('auditable_type', (new OfflineTripFlag)->getMorphClass())
            ->whereIn('auditable_id', $flagIds->map(fn ($id) => (string) $id))
            ->delete();

        // Deleting the rides takes their flags and state transitions with them.
        Ride::whereIn('id', $rideIds)->delete();
    }

    /**
     * @return array<string, Driver>
     */
    private function seedDrivers(City $lagos, VehicleClass $economy, VehicleClass $comfort): array
    {
        $definitions = [
            'kola' => ['Kola', 'Adebayo', '+2348300000021', $economy, ['Toyota', 'Corolla', 'White', 'LND-221AK']],
            'bisi' => ['Bisi', 'Okonkwo', '+2348300000022', $economy, ['Honda', 'Civic', 'Black', 'LND-222AL']],
            'musa' => ['Musa', 'Garba', '+2348300000023', $comfort, ['Toyota', 'Camry', 'Silver', 'LND-223AM']],
            'tayo' => ['Tayo', 'Bankole', '+2348300000024', $economy, ['Kia', 'Cerato', 'Blue', 'LND-224AN']],
            'ngozi' => ['Ngozi', 'Eze', '+2348300000025', $economy, ['Hyundai', 'Accent', 'Grey', 'LND-225AO']],
            'chidi' => ['Chidi', 'Nwosu', '+2348300000026', $comfort, ['Honda', 'Accord', 'Black', 'LND-226AP']],
        ];

        $drivers = [];

        foreach ($definitions as $key => [$firstName, $lastName, $phone, $class, [$make, $model, $colour, $plate]]) {
            $user = User::firstOrCreate(
                ['email' => $this->email("driver.compliance.{$key}"), 'type' => UserType::Driver],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'password' => Hash::make(self::PASSWORD),
                    'phone_verified_at' => now(),
                    'is_active' => true,
                ],
            );

            // Reset to a clean approved driver; seedFlags() applies the
            // sanction each driver's latest flag left them with.
            $driver = Driver::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'city_id' => $lagos->id,
                    'licence_number' => 'DEMOCMP'.strtoupper(Str::substr($key, 0, 3)).substr($phone, -4),
                    'status' => DriverStatus::Approved,
                    'approved_at' => now()->subDays(60),
                    'is_online' => false,
                    'suspended_at' => null,
                    'suspension_reason' => null,
                ],
            );

            Vehicle::updateOrCreate(
                ['driver_id' => $driver->id],
                [
                    'make' => $make,
                    'model' => $model,
                    'colour' => $colour,
                    'plate_number' => $plate,
                    'year' => 2020,
                    'fuel_type' => 'petrol',
                    'vehicle_class_id' => $class->id,
                ],
            );

            $drivers[$key] = $driver;
        }

        return $drivers;
    }

    /**
     * The same passengers as MonitoringDemoSeeder.
     *
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
        ];

        return collect($definitions)
            ->map(fn (array $data, string $key) => User::firstOrCreate(
                ['email' => $this->email("passenger.{$key}"), 'type' => UserType::Passenger],
                [
                    'first_name' => $data[0],
                    'last_name' => $data[1],
                    'phone' => $data[2],
                    'password' => Hash::make(self::PASSWORD),
                    'phone_verified_at' => now(),
                    'is_active' => true,
                ],
            ))
            ->all();
    }

    /**
     * Flags per driver, oldest first, so each tier matches what the sanction
     * ladder would have given (overturned flags don't count towards it).
     *
     * @param  array<string, Driver>  $drivers
     * @param  array<string, User>  $passengers
     */
    private function seedFlags(City $lagos, VehicleClass $economy, VehicleClass $comfort, array $drivers, array $passengers): void
    {
        $now = now();

        // Kola: a first offence from this morning, not disputed yet.
        $this->flag($lagos, $economy, $drivers['kola'], $passengers['amaka'], 'ikeja_mall', 'landmark', SanctionTier::Warning, $now->copy()->subHours(5), [
            'match' => 88.2, 'samples' => 17, 'on_route' => 15, 'cancel_m' => 120.4, 'duration' => 840, 'accuracy' => 7.6,
        ]);

        // Bisi: second offence yesterday, suspended 48 h, disputed in the app.
        $this->flag($lagos, $economy, $drivers['bisi'], $passengers['femi'], 'maryland', 'marina', SanctionTier::Warning, $now->copy()->subDays(18)->setTime(14, 10), [
            'match' => 71.4, 'samples' => 14, 'on_route' => 10, 'cancel_m' => 210.0, 'duration' => 560, 'accuracy' => 11.2,
        ]);
        $this->flag($lagos, $economy, $drivers['bisi'], $passengers['halima'], 'unilag', 'bourdillon', SanctionTier::Suspension, $now->copy()->subHours(20), [
            'match' => 93.8, 'samples' => 16, 'on_route' => 15, 'cancel_m' => 64.8, 'duration' => 870, 'accuracy' => 5.9,
        ], dispute: [
            'notes' => 'The passenger cancelled and asked me to drop them at the same place for cash. I said no and drove home to Lekki, which is the same road. I did not carry anybody.',
            'after_minutes' => 95,
        ]);

        // Musa: third offence two hours ago, suspended pending deactivation review.
        $this->flag($lagos, $comfort, $drivers['musa'], $passengers['uche'], 'airport', 'ikeja_gra', SanctionTier::Warning, $now->copy()->subDays(26)->setTime(9, 40), [
            'match' => 66.7, 'samples' => 12, 'on_route' => 8, 'cancel_m' => 245.3, 'duration' => 480, 'accuracy' => 14.0,
        ]);
        $this->flag($lagos, $comfort, $drivers['musa'], $passengers['kunle'], 'admiralty', 'airport', SanctionTier::Suspension, $now->copy()->subDays(13)->setTime(19, 5), [
            'match' => 84.6, 'samples' => 26, 'on_route' => 22, 'cancel_m' => 98.1, 'duration' => 1380, 'accuracy' => 8.3,
        ]);
        $this->flag($lagos, $comfort, $drivers['musa'], $passengers['precious'], 'landmark', 'ajah', SanctionTier::Deactivation, $now->copy()->subHours(2), [
            'match' => 96.3, 'samples' => 27, 'on_route' => 26, 'cancel_m' => 41.7, 'duration' => 1500, 'accuracy' => 4.8,
        ]);

        // Tayo: disputed and overturned (GPS drift on a shared road).
        $this->flag($lagos, $economy, $drivers['tayo'], $passengers['amaka'], 'stadium', 'marina', SanctionTier::Warning, $now->copy()->subDays(7)->setTime(8, 25), [
            'match' => 62.5, 'samples' => 8, 'on_route' => 5, 'cancel_m' => 280.9, 'duration' => 330, 'accuracy' => 38.5,
        ], dispute: [
            'notes' => 'I was going to my next pickup at Marina which you can see in my trip history. The passenger cancelled on me.',
            'after_minutes' => 240,
        ], review: [
            'outcome' => DisputeOutcome::Overturned,
            'notes' => 'Overturned: the driver accepted a Marina pickup 6 minutes after the cancellation and GPS accuracy was poor (38 m). Only 5 samples on the route.',
            'after_hours' => 20,
            'admin' => 'operations',
        ]);

        // Ngozi: first flag upheld after a dispute; second flag four days ago,
        // so the 48-hour suspension has already been lifted.
        $this->flag($lagos, $economy, $drivers['ngozi'], $passengers['femi'], 'gbagada', 'landmark', SanctionTier::Warning, $now->copy()->subDays(10)->setTime(17, 50), [
            'match' => 90.0, 'samples' => 20, 'on_route' => 18, 'cancel_m' => 75.2, 'duration' => 1020, 'accuracy' => 6.4,
        ], dispute: [
            'notes' => 'I did not pick anyone. My phone was in the car while my brother used it.',
            'after_minutes' => 300,
        ], review: [
            'outcome' => DisputeOutcome::Upheld,
            'notes' => 'Upheld: the passenger confirmed by phone that they paid the driver cash for the trip.',
            'after_hours' => 26,
            'admin' => 'operations',
        ]);
        $this->flag($lagos, $economy, $drivers['ngozi'], $passengers['halima'], 'ikeja_mall', 'unilag', SanctionTier::Suspension, $now->copy()->subDays(4)->setTime(12, 15), [
            'match' => 81.3, 'samples' => 16, 'on_route' => 13, 'cancel_m' => 150.6, 'duration' => 690, 'accuracy' => 9.1,
        ]);

        // Chidi: third offence yesterday, disputed, waiting for review.
        $this->flag($lagos, $comfort, $drivers['chidi'], $passengers['uche'], 'bourdillon', 'admiralty', SanctionTier::Warning, $now->copy()->subDays(22)->setTime(7, 30), [
            'match' => 75.0, 'samples' => 12, 'on_route' => 9, 'cancel_m' => 188.0, 'duration' => 510, 'accuracy' => 10.5,
        ]);
        $this->flag($lagos, $comfort, $drivers['chidi'], $passengers['kunle'], 'marina', 'airport', SanctionTier::Suspension, $now->copy()->subDays(15)->setTime(21, 0), [
            'match' => 87.5, 'samples' => 24, 'on_route' => 21, 'cancel_m' => 92.4, 'duration' => 1260, 'accuracy' => 7.0,
        ], dispute: [
            'notes' => 'The passenger said the app price was too high and cancelled. I left.',
            'after_minutes' => 600,
        ], review: [
            'outcome' => DisputeOutcome::Upheld,
            'notes' => 'Upheld: 21 of 24 samples on the passenger\'s route all the way to the airport.',
            'after_hours' => 30,
            'admin' => 'safety',
        ]);
        $this->flag($lagos, $comfort, $drivers['chidi'], $passengers['precious'], 'maryland', 'admiralty', SanctionTier::Deactivation, $now->copy()->subDays(1)->setTime(10, 45), [
            'match' => 91.7, 'samples' => 24, 'on_route' => 22, 'cancel_m' => 58.3, 'duration' => 1290, 'accuracy' => 6.1,
        ], dispute: [
            'notes' => 'This is not fair. I have done more than 400 trips. The customer is my regular and we were going the same way, I did not collect money.',
            'after_minutes' => 180,
        ]);

        usort($this->queued, fn (array $a, array $b) => $a['flaggedAt'] <=> $b['flaggedAt']);

        foreach ($this->queued as $spec) {
            $this->createFlag(...$spec);
        }
        $this->queued = [];

        $this->applyCurrentSanctions($drivers, $now);
    }

    /**
     * @param  array{match: float, samples: int, on_route: int, cancel_m: float, duration: int, accuracy: float}  $evidence
     * @param  array{notes: string, after_minutes: int}|null  $dispute
     * @param  array{outcome: DisputeOutcome, notes: string, after_hours: int, admin: string}|null  $review
     */
    private function flag(
        City $city,
        VehicleClass $class,
        Driver $driver,
        User $passenger,
        string $from,
        string $to,
        SanctionTier $tier,
        Carbon $flaggedAt,
        array $evidence,
        ?array $dispute = null,
        ?array $review = null,
    ): void {
        $this->queued[] = compact('city', 'class', 'driver', 'passenger', 'from', 'to', 'tier', 'flaggedAt', 'evidence', 'dispute', 'review');
    }

    /**
     * @param  array{match: float, samples: int, on_route: int, cancel_m: float, duration: int, accuracy: float}  $evidence
     * @param  array{notes: string, after_minutes: int}|null  $dispute
     * @param  array{outcome: DisputeOutcome, notes: string, after_hours: int, admin: string}|null  $review
     */
    private function createFlag(
        City $city,
        VehicleClass $class,
        Driver $driver,
        User $passenger,
        string $from,
        string $to,
        SanctionTier $tier,
        Carbon $flaggedAt,
        array $evidence,
        ?array $dispute = null,
        ?array $review = null,
    ): OfflineTripFlag {
        $analysisDelay = (int) config('offline_detection.analysis_delay_minutes', 20);
        $window = (int) config('offline_detection.monitoring_window_minutes', 15);
        $cancelledAt = $flaggedAt->copy()->subMinutes($analysisDelay + $window);

        $ride = $this->cancelledRide($city, $class, $driver, $passenger, $from, $to, $cancelledAt);

        $disputedAt = $dispute ? $flaggedAt->copy()->addMinutes($dispute['after_minutes']) : null;
        $resolvedAt = $review ? $disputedAt->copy()->addHours($review['after_hours']) : null;
        $reviewer = match ($review['admin'] ?? null) {
            'operations' => $this->operationsAdmin,
            'safety' => $this->safetyAdmin,
            default => null,
        };

        $flag = OfflineTripFlag::create([
            'ride_id' => $ride->id,
            'driver_id' => $driver->user_id,
            'passenger_id' => $passenger->id,
            'detection_data' => $this->detectionData($evidence, $cancelledAt),
            'sanction_tier' => $tier,
            'sanction_action' => $tier->action(),
            'is_disputed' => $dispute !== null,
            'dispute_notes' => $dispute['notes'] ?? null,
            'disputed_at' => $disputedAt,
            'dispute_channel' => $dispute ? 'driver_app' : null,
            'dispute_outcome' => $review['outcome'] ?? ($dispute ? DisputeOutcome::Pending : null),
            'dispute_resolved_by_admin_id' => $review ? $reviewer?->id : null,
            'review_notes' => $review['notes'] ?? null,
            'flagged_at' => $flaggedAt,
            'resolved_at' => $resolvedAt,
        ]);

        $flag->forceFill(['created_at' => $flaggedAt, 'updated_at' => $resolvedAt ?? $disputedAt ?? $flaggedAt])->saveQuietly();

        $this->audit($flag, 'offline_trip_flagged', null, null, [
            'sanction_tier' => $tier->value,
            'sanction_action' => $tier->action(),
            'ride_id' => $ride->id,
            'driver_id' => $driver->user_id,
        ], $flaggedAt);

        if ($dispute) {
            $this->audit($flag, 'offline_flag_disputed', $driver->user, null, [
                'dispute_notes' => $dispute['notes'],
            ], $disputedAt);
        }

        if ($review) {
            $this->audit($flag, 'offline_flag_reviewed', $reviewer, [
                'dispute_outcome' => DisputeOutcome::Pending->value,
            ], [
                'dispute_outcome' => $review['outcome']->value,
                'resolution_notes' => $review['notes'],
            ], $resolvedAt);
        }

        return $flag;
    }

    /**
     * The fields OfflineTripDetectionService and the cancellation context
     * record for a real flag.
     *
     * @param  array{match: float, samples: int, on_route: int, cancel_m: float, duration: int, accuracy: float}  $evidence
     * @return array<string, mixed>
     */
    private function detectionData(array $evidence, Carbon $cancelledAt): array
    {
        $threshold = (int) config('offline_detection.collocation_threshold_meters', 200);
        $interval = (int) config('offline_detection.location_sample_interval_seconds', 30);

        $distances = [];
        for ($i = 0; $i < $evidence['samples']; $i++) {
            $distances[] = $i < $evidence['on_route']
                ? round(15 + (($i * 37) % ($threshold - 40)), 1)
                : round($threshold + 40 + (($i * 53) % 400), 1);
        }
        // Spread the off-route samples through the trip rather than at the end.
        mt_srand(crc32($cancelledAt->toIso8601String()));
        shuffle($distances);
        mt_srand();

        $onRoute = array_filter($distances, fn (float $d) => $d <= $threshold);

        return [
            'flagged' => true,
            'collocation_points' => $evidence['on_route'],
            'total_trajectory_points' => $evidence['samples'],
            'route_match_percentage' => $evidence['match'],
            'average_distance_meters' => round(array_sum($onRoute) / max(count($onRoute), 1), 1),
            'point_distances' => $distances,
            'threshold_meters' => $threshold,
            'min_points_required' => (int) config('offline_detection.min_collocation_points', 3),
            'match_percentage_required' => (int) config('offline_detection.route_match_percentage', 60),
            'on_route_duration_seconds' => $evidence['duration'],
            'monitoring_duration_seconds' => max($evidence['duration'], ($evidence['samples'] - 1) * $interval),
            'average_gps_accuracy_meters' => $evidence['accuracy'],
            'cancelled_at' => $cancelledAt->toISOString(),
            'cancel_distance_from_pickup_meters' => $evidence['cancel_m'],
            'cancel_gps_accuracy_meters' => round($evidence['accuracy'] * 0.9, 1),
        ];
    }

    private function cancelledRide(
        City $city,
        VehicleClass $class,
        Driver $driver,
        User $passenger,
        string $from,
        string $to,
        Carbon $cancelledAt,
    ): Ride {
        [$pickupLat, $pickupLng, $pickupAddress] = self::PLACES[$from];
        [$destinationLat, $destinationLng, $destinationAddress] = self::PLACES[$to];

        $requestedAt = $cancelledAt->copy()->subMinutes(9);
        $matchedAt = $requestedAt->copy()->addSeconds(40);
        $enRouteAt = $matchedAt->copy()->addSeconds(20);
        $arrivedAt = $cancelledAt->copy()->subMinutes(2);

        // Most of these are passenger cancellations at the pickup; a few are
        // the driver's own, as the pattern goes both ways.
        $byDriver = crc32($passenger->id.$from) % 3 === 0;
        $reason = $byDriver
            ? CancellationReason::PassengerNoShow
            : (crc32($to) % 2 === 0 ? CancellationReason::FoundAlternative : CancellationReason::ChangedMind);

        $ride = Ride::forceCreate([
            'city_id' => $city->id,
            'vehicle_class_id' => $class->id,
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->user_id,
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'pickup_address' => $pickupAddress,
            'destination_lat' => $destinationLat,
            'destination_lng' => $destinationLng,
            'destination_address' => $destinationAddress,
            'status' => RideStatus::Cancelled,
            'share_token' => Str::random(32),
            'fare_estimate_amount' => $class->name === 'comfort' ? 6800 : 4500,
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
            'matched_at' => $matchedAt,
            'cancelled_by' => $byDriver ? $driver->user_id : $passenger->id,
            'cancellation_reason' => $reason->value,
            'created_at' => $requestedAt,
            'updated_at' => $cancelledAt,
        ]);

        $steps = [
            [null, RideStatus::Requested, $requestedAt, $passenger->id, 'passenger'],
            [RideStatus::Requested, RideStatus::Searching, $requestedAt->copy()->addSeconds(2), null, 'system'],
            [RideStatus::Searching, RideStatus::Matched, $matchedAt, $driver->user_id, 'driver'],
            [RideStatus::Matched, RideStatus::DriverEnRoute, $enRouteAt, $driver->user_id, 'driver'],
            [RideStatus::DriverEnRoute, RideStatus::DriverArrived, $arrivedAt, $driver->user_id, 'driver'],
            [RideStatus::DriverArrived, RideStatus::Cancelled, $cancelledAt, $byDriver ? $driver->user_id : $passenger->id, $byDriver ? 'driver' : 'passenger'],
        ];

        foreach ($steps as [$fromState, $toState, $at, $actorId, $actorType]) {
            RideStateTransition::create([
                'ride_id' => $ride->id,
                'from_state' => $fromState,
                'to_state' => $toState,
                'triggered_by_type' => $actorType,
                'triggered_by_id' => $actorId,
                'metadata' => ['demo' => true, 'seeder' => self::SEEDER_TAG],
                'created_at' => $at,
            ]);
        }

        return $ride;
    }

    /**
     * Leave each driver where their latest flag put them: suspended for 48 h
     * after an L2 (lifted once it has run), suspended pending review after an
     * L3, otherwise approved.
     *
     * @param  array<string, Driver>  $drivers
     */
    private function applyCurrentSanctions(array $drivers, Carbon $now): void
    {
        $hours = (int) config('offline_detection.suspension_hours', 48);

        foreach ($drivers as $driver) {
            $latest = OfflineTripFlag::forDriver($driver->user_id)->notOverturned()->latest('flagged_at')->first();

            if (! $latest) {
                continue;
            }

            $reason = match (true) {
                $latest->sanction_tier === SanctionTier::Deactivation => SanctionService::DEACTIVATION_REVIEW_REASON,
                $latest->sanction_tier === SanctionTier::Suspension && $latest->flagged_at->gt($now->copy()->subHours($hours)) => SanctionService::AUTOMATIC_SUSPENSION_REASON,
                default => null,
            };

            if ($reason) {
                $driver->update([
                    'status' => DriverStatus::Suspended,
                    'suspended_at' => $latest->flagged_at,
                    'suspension_reason' => $reason,
                ]);
            }
        }
    }

    /**
     * AuditLog::record() stamps the current time; history needs the time the
     * step happened.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function audit(OfflineTripFlag $flag, string $event, ?User $actor, ?array $oldValues, ?array $newValues, Carbon $at): void
    {
        AuditLog::create([
            'auditable_type' => $flag->getMorphClass(),
            'auditable_id' => $flag->getKey(),
            'event' => $event,
            'actor_id' => $actor?->id,
            'actor_type' => $actor?->type?->value,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => null,
            'user_agent' => self::SEEDER_TAG,
            'created_at' => $at,
        ]);
    }
}
