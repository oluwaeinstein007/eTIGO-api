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
use App\Services\OfflineTripDetectionService;
use App\Services\RideRouteService;
use App\Services\SanctionService;
use App\Support\Polyline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test data for the admin Offline Trip Flags (compliance) screens: eleven
 * drivers whose flags cover every status (flagged, disputed, upheld,
 * overturned) at every sanction level, plus a confirmed deactivation, an
 * appeal and a verified cash-taking case. Each flag is on a cancelled ride,
 * with detection evidence and a route map made by running the real detection
 * on a driver path along the passenger's route, and a review history.
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

        $this->command?->info('Offline trip flag demo data ready: 22 flags for 11 drivers (GET /admin/offline-flags).');
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
            'ada' => ['Ada', 'Obi', '+2348300000027', $economy, ['Toyota', 'Yaris', 'Red', 'LND-227AQ']],
            'segun' => ['Segun', 'Alabi', '+2348300000028', $comfort, ['Lexus', 'ES 350', 'Black', 'LND-228AR']],
            'yemi' => ['Yemi', 'Ogunleye', '+2348300000029', $economy, ['Nissan', 'Sentra', 'White', 'LND-229AS']],
            'emeka' => ['Emeka', 'Udeh', '+2348300000030', $comfort, ['Toyota', 'Avalon', 'Grey', 'LND-230AT']],
            'funke' => ['Funke', 'Ajayi', '+2348300000031', $economy, ['Hyundai', 'Elantra', 'Blue', 'LND-231AU']],
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
            'match' => 88.2, 'samples' => 17, 'on_route' => 15, 'cancel_m' => 120.4, 'accuracy' => 7.6,
        ]);

        // Bisi: second offence yesterday, suspended 48 h, disputed in the app.
        $this->flag($lagos, $economy, $drivers['bisi'], $passengers['femi'], 'maryland', 'marina', SanctionTier::Warning, $now->copy()->subDays(18)->setTime(14, 10), [
            'match' => 71.4, 'samples' => 14, 'on_route' => 10, 'cancel_m' => 210.0, 'accuracy' => 11.2,
        ]);
        $this->flag($lagos, $economy, $drivers['bisi'], $passengers['halima'], 'unilag', 'bourdillon', SanctionTier::Suspension, $now->copy()->subHours(20), [
            'match' => 93.8, 'samples' => 16, 'on_route' => 15, 'cancel_m' => 64.8, 'accuracy' => 5.9,
        ], dispute: [
            'notes' => 'The passenger cancelled and asked me to drop them at the same place for cash. I said no and drove home to Lekki, which is the same road. I did not carry anybody.',
            'after_minutes' => 95,
        ]);

        // Musa: third offence three days ago, not disputed in the 48 hours he
        // had, so the deactivation can be confirmed.
        $this->flag($lagos, $comfort, $drivers['musa'], $passengers['uche'], 'airport', 'ikeja_gra', SanctionTier::Warning, $now->copy()->subDays(26)->setTime(9, 40), [
            'match' => 66.7, 'samples' => 12, 'on_route' => 8, 'cancel_m' => 245.3, 'accuracy' => 14.0,
        ]);
        $this->flag($lagos, $comfort, $drivers['musa'], $passengers['kunle'], 'admiralty', 'airport', SanctionTier::Suspension, $now->copy()->subDays(13)->setTime(19, 5), [
            'match' => 84.6, 'samples' => 26, 'on_route' => 22, 'cancel_m' => 98.1, 'accuracy' => 8.3,
        ]);
        $this->flag($lagos, $comfort, $drivers['musa'], $passengers['precious'], 'landmark', 'ajah', SanctionTier::Deactivation, $now->copy()->subDays(3)->setTime(8, 15), [
            'match' => 96.3, 'samples' => 27, 'on_route' => 26, 'cancel_m' => 41.7, 'accuracy' => 4.8,
        ]);

        // Tayo: disputed and overturned (GPS drift on a shared road).
        $this->flag($lagos, $economy, $drivers['tayo'], $passengers['amaka'], 'stadium', 'marina', SanctionTier::Warning, $now->copy()->subDays(7)->setTime(8, 25), [
            'match' => 62.5, 'samples' => 8, 'on_route' => 5, 'cancel_m' => 280.9, 'accuracy' => 38.5,
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
            'match' => 90.0, 'samples' => 20, 'on_route' => 18, 'cancel_m' => 75.2, 'accuracy' => 6.4,
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
            'match' => 81.3, 'samples' => 16, 'on_route' => 13, 'cancel_m' => 150.6, 'accuracy' => 9.1,
        ]);

        // Chidi: third offence yesterday, disputed, waiting for review.
        $this->flag($lagos, $comfort, $drivers['chidi'], $passengers['uche'], 'bourdillon', 'admiralty', SanctionTier::Warning, $now->copy()->subDays(22)->setTime(7, 30), [
            'match' => 75.0, 'samples' => 12, 'on_route' => 9, 'cancel_m' => 188.0, 'accuracy' => 10.5,
        ]);
        $this->flag($lagos, $comfort, $drivers['chidi'], $passengers['kunle'], 'marina', 'airport', SanctionTier::Suspension, $now->copy()->subDays(15)->setTime(21, 0), [
            'match' => 87.5, 'samples' => 24, 'on_route' => 21, 'cancel_m' => 92.4, 'accuracy' => 7.0,
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
            'match' => 91.7, 'samples' => 24, 'on_route' => 22, 'cancel_m' => 58.3, 'accuracy' => 6.1,
        ], dispute: [
            'notes' => 'This is not fair. I have done more than 400 trips. The customer is my regular and we were going the same way, I did not collect money.',
            'after_minutes' => 180,
        ]);

        // Ada: a first offence this morning, disputed straight away.
        $this->flag($lagos, $economy, $drivers['ada'], $passengers['halima'], 'stadium', 'landmark', SanctionTier::Warning, $now->copy()->subHours(7), [
            'match' => 76.9, 'samples' => 13, 'on_route' => 10, 'cancel_m' => 135.0, 'accuracy' => 9.4,
        ], dispute: [
            'notes' => 'The lady cancelled because she said I was late. I went to fuel my car at Costain, that is why my phone was on that road.',
            'after_minutes' => 40,
        ]);

        // Segun: third offence three days ago, no dispute within 48 hours,
        // then upheld and deactivation confirmed by Safety in one step.
        $this->flag($lagos, $comfort, $drivers['segun'], $passengers['kunle'], 'ikeja_gra', 'maryland', SanctionTier::Warning, $now->copy()->subDays(25)->setTime(11, 20), [
            'match' => 70.0, 'samples' => 10, 'on_route' => 7, 'cancel_m' => 230.0, 'accuracy' => 12.5,
        ]);
        $this->flag($lagos, $comfort, $drivers['segun'], $passengers['amaka'], 'airport', 'admiralty', SanctionTier::Suspension, $now->copy()->subDays(12)->setTime(6, 50), [
            'match' => 89.5, 'samples' => 19, 'on_route' => 17, 'cancel_m' => 77.0, 'accuracy' => 6.8,
        ]);
        $this->flag($lagos, $comfort, $drivers['segun'], $passengers['uche'], 'marina', 'ajah', SanctionTier::Deactivation, $now->copy()->subDays(3)->setTime(18, 30), [
            'match' => 95.8, 'samples' => 24, 'on_route' => 23, 'cancel_m' => 52.0, 'accuracy' => 5.2,
        ], deactivation: [
            'notes' => 'Third off-app trip in 30 days. The passenger confirmed by phone that she paid the driver ₦9,000 cash for the trip to Ajah.',
            'after_hours' => 50,
            'admin' => 'safety',
        ]);

        // Yemi: first flag upheld; second upheld, then overturned on appeal
        // by a different reviewer.
        $this->flag($lagos, $economy, $drivers['yemi'], $passengers['precious'], 'unilag', 'marina', SanctionTier::Warning, $now->copy()->subDays(20)->setTime(13, 5), [
            'match' => 83.3, 'samples' => 18, 'on_route' => 15, 'cancel_m' => 160.0, 'accuracy' => 8.0,
        ], review: [
            'outcome' => DisputeOutcome::Upheld,
            'notes' => 'Upheld: 15 of 18 samples on the passenger\'s route, no other trip accepted in that window.',
            'after_hours' => 9,
            'admin' => 'operations',
        ]);
        $this->flag($lagos, $economy, $drivers['yemi'], $passengers['femi'], 'maryland', 'unilag', SanctionTier::Suspension, $now->copy()->subDays(6)->setTime(16, 40), [
            'match' => 78.6, 'samples' => 14, 'on_route' => 11, 'cancel_m' => 110.0, 'accuracy' => 10.2,
        ], dispute: [
            'notes' => 'I live in Akoka. After the passenger cancelled I drove home, which is the same road to Unilag.',
            'after_minutes' => 120,
        ], review: [
            'outcome' => DisputeOutcome::Upheld,
            'notes' => 'Upheld: the path follows the booked route to the gate, not the driver\'s street.',
            'after_hours' => 18,
            'admin' => 'operations',
        ], appeal: [
            'notes' => 'Overturned on appeal: the driver sent a utility bill for an address inside the Unilag staff quarters, on the booked route.',
            'after_hours' => 30,
            'admin' => 'safety',
        ]);

        // Emeka: third offence disputed and overturned; the earlier
        // suspension has run, so he is approved again.
        $this->flag($lagos, $comfort, $drivers['emeka'], $passengers['halima'], 'bourdillon', 'landmark', SanctionTier::Warning, $now->copy()->subDays(28)->setTime(10, 0), [
            'match' => 72.7, 'samples' => 11, 'on_route' => 8, 'cancel_m' => 190.0, 'accuracy' => 11.0,
        ]);
        $this->flag($lagos, $comfort, $drivers['emeka'], $passengers['precious'], 'admiralty', 'marina', SanctionTier::Suspension, $now->copy()->subDays(14)->setTime(20, 15), [
            'match' => 85.0, 'samples' => 20, 'on_route' => 17, 'cancel_m' => 95.0, 'accuracy' => 7.4,
        ]);
        $this->flag($lagos, $comfort, $drivers['emeka'], $passengers['kunle'], 'landmark', 'airport', SanctionTier::Deactivation, $now->copy()->subDays(2)->setTime(9, 10), [
            'match' => 63.6, 'samples' => 11, 'on_route' => 7, 'cancel_m' => 270.0, 'accuracy' => 41.0,
        ], dispute: [
            'notes' => 'I had an airport pickup booked on the app right after. Please check my trips.',
            'after_minutes' => 60,
        ], review: [
            'outcome' => DisputeOutcome::Overturned,
            'notes' => 'Overturned: the driver accepted an airport request 4 minutes after the cancellation and GPS accuracy was poor (41 m).',
            'after_hours' => 6,
            'admin' => 'safety',
        ]);

        // Funke: a first offence three hours ago; the passenger called support
        // to say they paid cash, so Operations moved it straight to Level 3.
        // She has until 48 hours after that to respond.
        $this->flag($lagos, $economy, $drivers['funke'], $passengers['femi'], 'gbagada', 'ikeja_mall', SanctionTier::Deactivation, $now->copy()->subHours(3), [
            'match' => 90.9, 'samples' => 22, 'on_route' => 20, 'cancel_m' => 66.0, 'accuracy' => 6.0,
        ], cashTaking: [
            'notes' => 'Passenger called support: the driver cancelled at the pickup and asked for ₦4,000 cash to do the trip off the app. Passenger paid.',
            'after_minutes' => 50,
            'admin' => 'operations',
            'from_tier' => SanctionTier::Warning,
        ]);

        usort($this->queued, fn (array $a, array $b) => $a['flaggedAt'] <=> $b['flaggedAt']);

        foreach ($this->queued as $spec) {
            $this->createFlag(...$spec);
        }
        $this->queued = [];

        $this->applyCurrentSanctions($drivers);
    }

    /**
     * @param  array{match: float, samples: int, on_route: int, cancel_m: float, accuracy: float}  $evidence
     * @param  array{notes: string, after_minutes: int}|null  $dispute
     * @param  array{outcome: DisputeOutcome, notes: string, after_hours: int, admin: string}|null  $review
     * @param  array{notes: string, after_hours: int, admin: string}|null  $appeal  Overturns an upheld review
     * @param  array{notes: string, after_hours: int, admin: string}|null  $deactivation  Upholds and confirms a Level 3
     * @param  array{notes: string, after_minutes: int, admin: string, from_tier: SanctionTier}|null  $cashTaking
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
        ?array $appeal = null,
        ?array $deactivation = null,
        ?array $cashTaking = null,
    ): void {
        $this->queued[] = compact('city', 'class', 'driver', 'passenger', 'from', 'to', 'tier', 'flaggedAt', 'evidence', 'dispute', 'review', 'appeal', 'deactivation', 'cashTaking');
    }

    /**
     * @param  array{match: float, samples: int, on_route: int, cancel_m: float, accuracy: float}  $evidence
     * @param  array{notes: string, after_minutes: int}|null  $dispute
     * @param  array{outcome: DisputeOutcome, notes: string, after_hours: int, admin: string}|null  $review
     * @param  array{notes: string, after_hours: int, admin: string}|null  $appeal
     * @param  array{notes: string, after_hours: int, admin: string}|null  $deactivation
     * @param  array{notes: string, after_minutes: int, admin: string, from_tier: SanctionTier}|null  $cashTaking
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
        ?array $appeal = null,
        ?array $deactivation = null,
        ?array $cashTaking = null,
    ): OfflineTripFlag {
        $analysisDelay = (int) config('offline_detection.analysis_delay_minutes', 20);
        $window = (int) config('offline_detection.monitoring_window_minutes', 15);
        $cancelledAt = $flaggedAt->copy()->subMinutes($analysisDelay + $window);

        $ride = $this->cancelledRide($city, $class, $driver, $passenger, $from, $to, $cancelledAt);

        $disputedAt = $dispute ? $flaggedAt->copy()->addMinutes($dispute['after_minutes']) : null;
        $reviewedAt = $review ? ($disputedAt ?? $flaggedAt)->copy()->addHours($review['after_hours']) : null;
        $appealedAt = $appeal ? $reviewedAt->copy()->addHours($appeal['after_hours']) : null;
        $confirmedAt = $deactivation ? ($reviewedAt ?? $disputedAt ?? $flaggedAt)->copy()->addHours($deactivation['after_hours']) : null;
        $cashVerifiedAt = $cashTaking ? $flaggedAt->copy()->addMinutes($cashTaking['after_minutes']) : null;

        $reviewer = $this->adminFor($review['admin'] ?? null);
        $appealReviewer = $this->adminFor($appeal['admin'] ?? null);
        $confirmer = $this->adminFor($deactivation['admin'] ?? null);
        $cashVerifier = $this->adminFor($cashTaking['admin'] ?? null);

        // Where the review ended up: an appeal replaces the first decision,
        // and confirming a deactivation on an unreviewed flag upholds it.
        [$outcome, $reviewNotes, $resolvedBy, $resolvedAt] = match (true) {
            $appeal !== null => [DisputeOutcome::Overturned, $appeal['notes'], $appealReviewer, $appealedAt],
            $review !== null => [$review['outcome'], $review['notes'], $reviewer, $reviewedAt],
            $deactivation !== null => [DisputeOutcome::Upheld, $deactivation['notes'], $confirmer, $confirmedAt],
            default => [$dispute ? DisputeOutcome::Pending : null, null, null, null],
        };

        $flag = OfflineTripFlag::create([
            'ride_id' => $ride->id,
            'driver_id' => $driver->user_id,
            'passenger_id' => $passenger->id,
            'detection_data' => $this->detectionData($evidence, $cancelledAt, $ride),
            'sanction_tier' => $tier,
            'sanction_action' => $deactivation ? 'deactivated' : $tier->action(),
            'deactivation_response_due_at' => $tier === SanctionTier::Deactivation
                ? ($cashVerifiedAt ?? $flaggedAt)->copy()->addHours((int) config('offline_detection.deactivation_response_hours', 48))
                : null,
            'is_disputed' => $dispute !== null,
            'dispute_notes' => $dispute['notes'] ?? null,
            'disputed_at' => $disputedAt,
            'dispute_channel' => $dispute ? 'driver_app' : null,
            'dispute_outcome' => $outcome,
            'dispute_resolved_by_admin_id' => $resolvedBy?->id,
            'review_notes' => $reviewNotes,
            'deactivation_confirmed_at' => $confirmedAt,
            'deactivation_confirmed_by_admin_id' => $confirmer?->id,
            'deactivation_notes' => $deactivation['notes'] ?? null,
            'cash_taking_verified_at' => $cashVerifiedAt,
            'cash_taking_verified_by_admin_id' => $cashVerifier?->id,
            'cash_taking_notes' => $cashTaking['notes'] ?? null,
            'flagged_at' => $flaggedAt,
            'resolved_at' => $resolvedAt,
        ]);

        $lastStep = max(array_filter([$flaggedAt, $disputedAt, $reviewedAt, $appealedAt, $confirmedAt, $cashVerifiedAt]));
        $flag->forceFill(['created_at' => $flaggedAt, 'updated_at' => $lastStep])->saveQuietly();

        $flaggedTier = $cashTaking['from_tier'] ?? $tier;

        $this->audit($flag, 'offline_trip_flagged', null, null, [
            'sanction_tier' => $flaggedTier->value,
            'sanction_action' => $flaggedTier->action(),
            'ride_id' => $ride->id,
            'driver_id' => $driver->user_id,
        ], $flaggedAt);

        if ($cashTaking) {
            $this->audit($flag, 'offline_flag_cash_taking_verified', $cashVerifier, [
                'sanction_tier' => $flaggedTier->value,
            ], [
                'sanction_tier' => $tier->value,
                'notes' => $cashTaking['notes'],
            ], $cashVerifiedAt);
        }

        if ($dispute) {
            $this->audit($flag, 'offline_flag_disputed', $driver->user, null, [
                'dispute_notes' => $dispute['notes'],
            ], $disputedAt);
        }

        if ($review) {
            $this->audit($flag, 'offline_flag_reviewed', $reviewer, [
                'dispute_outcome' => $dispute ? DisputeOutcome::Pending->value : null,
            ], [
                'dispute_outcome' => $review['outcome']->value,
                'resolution_notes' => $review['notes'],
            ], $reviewedAt);
        }

        if ($appeal) {
            $this->audit($flag, 'offline_flag_reviewed', $appealReviewer, [
                'dispute_outcome' => DisputeOutcome::Upheld->value,
            ], [
                'dispute_outcome' => DisputeOutcome::Overturned->value,
                'resolution_notes' => $appeal['notes'],
            ], $appealedAt);
        }

        if ($deactivation) {
            $this->audit($flag, 'offline_flag_deactivation_confirmed', $confirmer, [
                'status' => 'suspended',
            ], [
                'status' => 'deactivated',
                'confirmed_by' => $confirmer?->id,
                'notes' => $deactivation['notes'],
                ...($review ? [] : ['dispute_outcome' => DisputeOutcome::Upheld->value]),
            ], $confirmedAt);
        }

        return $flag;
    }

    private function adminFor(?string $role): ?User
    {
        return match ($role) {
            'operations' => $this->operationsAdmin,
            'safety' => $this->safetyAdmin,
            default => null,
        };
    }

    /**
     * Run the real detection on a driver path made to match the evidence:
     * one sample every 30 seconds after the cancellation, moving along the
     * passenger's route, with the on-route samples inside the corridor and
     * the rest well outside it. The route is the planned driving route when
     * the maps provider gives one, else the straight line.
     *
     * @param  array{match: float, samples: int, on_route: int, cancel_m: float, accuracy: float}  $evidence
     * @return array<string, mixed>
     */
    private function detectionData(array $evidence, Carbon $cancelledAt, Ride $ride): array
    {
        $threshold = (int) config('offline_detection.collocation_threshold_meters', 200);
        $interval = (int) config('offline_detection.location_sample_interval_seconds', 30);

        $polyline = app(RideRouteService::class)->getPlannedRoute($ride)['encoded_polyline'] ?? null;
        $route = $polyline ? Polyline::decode($polyline) : [];
        if (count($route) < 2) {
            $route = [
                ['lat' => (float) $ride->pickup_lat, 'lng' => (float) $ride->pickup_lng],
                ['lat' => (float) $ride->destination_lat, 'lng' => (float) $ride->destination_lng],
            ];
        }

        // Which samples are on the route, spread through the trip, and how
        // far each is from it: the same on every run for the same dates.
        mt_srand(crc32($cancelledAt->toIso8601String()));
        $onRoute = array_merge(
            array_fill(0, $evidence['on_route'], true),
            array_fill(0, $evidence['samples'] - $evidence['on_route'], false),
        );
        shuffle($onRoute);

        $trajectory = [];
        foreach ($onRoute as $i => $isOnRoute) {
            $offset = $isOnRoute
                ? 15 + mt_rand(0, $threshold - 55)
                : $threshold + 150 + mt_rand(0, 450);
            $progress = $evidence['samples'] > 1 ? $i / ($evidence['samples'] - 1) : 0.0;

            [$lat, $lng] = $this->pointBeside($route, $progress, $i % 2 ? $offset : -$offset);

            $trajectory[] = [
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'timestamp' => $cancelledAt->copy()->addSeconds(($i + 1) * $interval)->timestamp,
                'speed' => 0,
                'heading' => 0,
                'accuracy' => $evidence['accuracy'],
            ];
        }
        mt_srand();

        $result = app(OfflineTripDetectionService::class)->analyzeCollocation($trajectory, [
            'pickup_lat' => (float) $ride->pickup_lat,
            'pickup_lng' => (float) $ride->pickup_lng,
            'destination_lat' => (float) $ride->destination_lat,
            'destination_lng' => (float) $ride->destination_lng,
        ], $polyline);

        // The driver was cancel_m from the pickup, coming from the south-west.
        $metresPerDegree = 6371000 * M_PI / 180;
        $cancelLat = (float) $ride->pickup_lat - ($evidence['cancel_m'] * 0.6) / $metresPerDegree;
        $cancelLng = (float) $ride->pickup_lng - ($evidence['cancel_m'] * 0.8) / ($metresPerDegree * cos(deg2rad((float) $ride->pickup_lat)));

        return [
            ...$result,
            'cancelled_at' => $cancelledAt->toISOString(),
            'cancel_distance_from_pickup_meters' => $evidence['cancel_m'],
            'cancel_gps_accuracy_meters' => round($evidence['accuracy'] * 0.9, 1),
            'cancel_lat' => round($cancelLat, 6),
            'cancel_lng' => round($cancelLng, 6),
        ];
    }

    /**
     * A point part of the way along a route (by distance), moved sideways
     * from it by the given metres (negative is the other side).
     *
     * @param  list<array{lat: float, lng: float}>  $route
     * @return array{0: float, 1: float}
     */
    private function pointBeside(array $route, float $progress, float $sideways): array
    {
        $metresPerDegree = 6371000 * M_PI / 180;
        $lngScale = cos(deg2rad($route[0]['lat']));

        $segments = [];
        $total = 0.0;
        for ($i = 1, $count = count($route); $i < $count; $i++) {
            $dx = ($route[$i]['lng'] - $route[$i - 1]['lng']) * $lngScale * $metresPerDegree;
            $dy = ($route[$i]['lat'] - $route[$i - 1]['lat']) * $metresPerDegree;
            $segments[] = ['start' => $route[$i - 1], 'dx' => $dx, 'dy' => $dy, 'length' => hypot($dx, $dy)];
            $total += hypot($dx, $dy);
        }

        $remaining = $progress * $total;
        foreach ($segments as $index => $segment) {
            if ($remaining > $segment['length'] && $index < count($segments) - 1) {
                $remaining -= $segment['length'];

                continue;
            }

            $length = $segment['length'] ?: 1.0;
            $t = min(1.0, $remaining / $length);
            // Unit vector at right angles to the segment.
            [$nx, $ny] = [-$segment['dy'] / $length, $segment['dx'] / $length];

            return [
                $segment['start']['lat'] + ($t * $segment['dy'] + $ny * $sideways) / $metresPerDegree,
                $segment['start']['lng'] + ($t * $segment['dx'] + $nx * $sideways) / ($lngScale * $metresPerDegree),
            ];
        }

        return [$route[0]['lat'], $route[0]['lng']];
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
     * Leave each driver where their latest flag that still counts put them:
     * deactivated after a confirmed Level 3, suspended pending review after
     * an unconfirmed one, suspended for 48 h after an L2 (lifted once it has
     * run), otherwise approved.
     *
     * @param  array<string, Driver>  $drivers
     */
    private function applyCurrentSanctions(array $drivers): void
    {
        foreach ($drivers as $driver) {
            app(SanctionService::class)->applyStandingSanction($driver);
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
