<?php

namespace Database\Seeders;

use App\Enums\DocumentType;
use App\Enums\DriverShiftStatus;
use App\Enums\DriverStatus;
use App\Enums\FuelType;
use App\Enums\KycStatus;
use App\Enums\MaintenanceItemType;
use App\Enums\MaintenanceRecordStatus;
use App\Enums\RepairRequestStatus;
use App\Enums\RepairServiceType;
use App\Enums\UserType;
use App\Enums\VehicleOwnershipType;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverAvailabilityPlan;
use App\Models\DriverDocument;
use App\Models\DriverRepair;
use App\Models\DriverShift;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleMaintenanceRecord;
use App\Services\VehicleMaintenanceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Driver scheduling and vehicle upkeep for the admin Fleet, Availability and
 * Maintenance screens, all in Lagos:
 *
 * - Availability plans for the next week, by area, from the Lagos drivers.
 * - A shared fleet Corolla in shift mode with two drivers: shifts completed,
 *   missed and cancelled in the past few days, and scheduled for the next two.
 * - Maintenance: services and tyre checks on record (one service overdue),
 *   one record awaiting review and one rejected, insurance expired on one
 *   car (blocked once the grace days pass) and roadworthiness due soon on
 *   another. Statuses come from VehicleMaintenanceService::refresh().
 * - Repair requests in progress, scheduled and completed.
 *
 * Every driver here is @ops-demo.etigo.com, so none is offered real rides.
 *
 * php artisan db:seed --class=DriverOperationsDemoSeeder
 */
class DriverOperationsDemoSeeder extends Seeder
{
    private const AREAS = ['Ikeja', 'Lekki', 'Victoria Island', 'Yaba', 'Surulere', 'Ikoyi', 'Ajah', 'Maryland'];

    public function run(VehicleMaintenanceService $maintenance): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping DriverOperationsDemoSeeder in production.');

            return;
        }

        $lagos = City::where('slug', 'lagos')->first();
        $admin = User::where('email', 'ops@etigo.com')->first();
        $drivers = Driver::where('city_id', $lagos?->id)
            ->where('status', DriverStatus::Approved)
            ->whereHas('user', fn ($q) => $q->where('email', 'like', '%.lagos@ops-demo.etigo.com'))
            ->with('user', 'vehicle')
            ->orderBy('created_at')
            ->get();

        if (! $lagos || ! $admin || $drivers->count() < 6) {
            $this->command?->warn('Run AdminSeeder and CityActivityDemoSeeder first.');

            return;
        }

        if (DriverAvailabilityPlan::whereIn('driver_id', $drivers->modelKeys())->exists()) {
            return;
        }

        // One transaction: a failure leaves nothing behind, so a rerun
        // seeds every scenario instead of stopping at the guard above.
        DB::transaction(function () use ($lagos, $admin, $drivers, $maintenance) {
            $this->availability($lagos, $drivers);
            $this->fleetShifts($lagos, $admin);
            $this->maintenance($drivers, $admin, $maintenance);
            $this->repairs($drivers);
        });
    }

    /**
     * @param  Collection<int, Driver>  $drivers
     */
    private function availability(City $city, Collection $drivers): void
    {
        foreach ($drivers->take(5) as $n => $driver) {
            $early = $n % 2 === 0;

            for ($day = 1; $day <= 7; $day++) {
                // Most drivers take a day or two off a week.
                if (($day + $n) % 6 === 0) {
                    continue;
                }

                $date = now()->addDays($day)->setTimezone('Africa/Lagos')->startOfDay();
                $startsAt = $early ? $date->copy()->setTime(6, 0) : $date->copy()->setTime(15, 0);

                DriverAvailabilityPlan::create([
                    'driver_id' => $driver->id,
                    'city_id' => $city->id,
                    'area_label' => self::AREAS[($n + $day) % count(self::AREAS)],
                    'starts_at' => $startsAt->utc(),
                    'ends_at' => $startsAt->copy()->addHours($early ? 8 : 7)->utc(),
                ]);
            }
        }
    }

    private function fleetShifts(City $city, User $admin): void
    {
        $economy = VehicleClass::where('name', 'economy')->first();

        $car = Vehicle::firstOrCreate(['plate_number' => 'KJA-910FL'], [
            'make' => 'Toyota',
            'model' => 'Corolla',
            'colour' => 'White',
            'year' => 2021,
            'fuel_type' => FuelType::Cng,
            'vehicle_class_id' => $economy->id,
            'is_fleet' => true,
            'shift_mode' => true,
            'odometer_km' => 61_240,
            'odometer_read_at' => now()->subDay(),
        ]);

        $shiftDrivers = [];
        foreach ([['Rasheed', 'Adewale', '+2349069900001'], ['Chukwudi', 'Eze', '+2349069900002']] as [$first, $last, $phone]) {
            $user = User::firstOrCreate(['email' => strtolower("{$first}.{$last}.fleet@ops-demo.etigo.com")], [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'type' => UserType::Driver,
                'password' => Hash::make('Qwer!234'),
                'is_active' => true,
            ]);
            // Not mass assignable on User.
            $user->phone_verified_at ??= now()->subMonths(2);
            $user->saveQuietly();

            $shiftDrivers[] = Driver::firstOrCreate(['user_id' => $user->id], [
                'city_id' => $city->id,
                'vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle,
                'status' => DriverStatus::Approved,
                'kyc_status' => KycStatus::Verified,
                'kyc_verified_at' => now()->subMonths(2),
                'licence_number' => sprintf('LAG%05dFL%02d', 40000 + count($shiftDrivers), 26 + count($shiftDrivers)),
                'is_online' => false,
                'approved_at' => now()->subMonths(2),
            ]);
        }

        [$day, $night] = $shiftDrivers;
        $lagosDay = fn (int $offset) => now()->setTimezone('Africa/Lagos')->addDays($offset)->startOfDay();

        $shifts = [
            [$day, -3, 6, DriverShiftStatus::Completed],
            [$night, -3, 14, DriverShiftStatus::Completed],
            [$day, -2, 6, DriverShiftStatus::Completed],
            [$night, -2, 14, DriverShiftStatus::Missed],
            [$day, -1, 6, DriverShiftStatus::Completed],
            [$night, -1, 14, DriverShiftStatus::Completed],
            [$day, 1, 6, DriverShiftStatus::Scheduled],
            [$night, 1, 14, DriverShiftStatus::Cancelled],
            [$day, 2, 6, DriverShiftStatus::Scheduled],
            [$night, 2, 14, DriverShiftStatus::Scheduled],
        ];

        foreach ($shifts as [$driver, $offset, $hour, $status]) {
            $startsAt = $lagosDay($offset)->setTime($hour, 0)->utc();
            $endsAt = $startsAt->copy()->addHours(8);

            DriverShift::create([
                'driver_id' => $driver->id,
                'vehicle_id' => $car->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $status,
                'started_at' => $status === DriverShiftStatus::Completed ? $startsAt->copy()->addMinutes(random_int(2, 14)) : null,
                'ended_at' => $status === DriverShiftStatus::Completed ? $endsAt->copy()->subMinutes(random_int(0, 10)) : null,
                'missed_at' => $status === DriverShiftStatus::Missed ? $startsAt->copy()->addMinutes(30) : null,
                'cancelled_at' => $status === DriverShiftStatus::Cancelled ? now()->subHours(5) : null,
                'cancellation_reason' => $status === DriverShiftStatus::Cancelled ? 'Car booked in for its 60,000 km service.' : null,
                'notes' => $hour === 6 ? 'Morning shift: hand over at the Ikeja depot by 2pm.' : 'Evening shift: car back at the depot by 10pm.',
                'created_by_admin_id' => $admin->id,
            ]);
        }
    }

    /**
     * @param  Collection<int, Driver>  $drivers
     */
    private function maintenance(Collection $drivers, User $admin, VehicleMaintenanceService $maintenance): void
    {
        $workshops = ['Toyota Nigeria Service Centre, Ikeja', 'Coscharis Motors, Victoria Island', 'Tyre Plus, Allen Avenue', 'Autofix Garage, Ogba'];

        foreach ($drivers as $n => $driver) {
            $vehicle = $driver->vehicle;

            if (! $vehicle) {
                continue;
            }

            $odometer = (int) $vehicle->odometer_km;

            // A service and a tyre check on record. The first car is past its
            // service by distance; the others are on schedule.
            $serviceKm = $n === 0 ? $odometer - 5_600 : $odometer - 1_800;
            $this->record($vehicle, MaintenanceItemType::Service, now()->subWeeks($n === 0 ? 11 : 4), $serviceKm, $workshops[$n % 4], MaintenanceRecordStatus::Approved, $admin);
            $this->record($vehicle, MaintenanceItemType::TyresBrakes, now()->subMonths(2), $odometer - 3_100, 'Tyre Plus, Allen Avenue', MaintenanceRecordStatus::Approved, $admin);

            match ($n) {
                1 => $this->record($vehicle, MaintenanceItemType::TyresBrakes, now()->subDay(), $odometer - 40, 'Tyre Plus, Allen Avenue', MaintenanceRecordStatus::Pending, null, 'Replaced front brake pads and rotated tyres.'),
                2 => $this->record($vehicle, MaintenanceItemType::Service, now()->subDays(3), $odometer - 120, 'Roadside mechanic, Ojota', MaintenanceRecordStatus::Rejected, $admin, 'Oil and filter change.', 'The receipt photo is too blurry to read the date and mileage. Please upload a clearer one.'),
                default => null,
            };

            // Insurance lapsed on one car, roadworthiness nearly due on another.
            match ($n) {
                3 => $this->expire($vehicle, DocumentType::InsuranceCertificate, now()->subDays(10)),
                4 => $this->expire($vehicle, DocumentType::RoadworthinessCertificate, now()->addDays(9)),
                default => null,
            };

            $maintenance->refresh($vehicle->fresh());
        }
    }

    private function record(Vehicle $vehicle, MaintenanceItemType $type, Carbon $performedAt, int $odometer, string $workshop, MaintenanceRecordStatus $status, ?User $reviewer, ?string $notes = null, ?string $rejection = null): void
    {
        VehicleMaintenanceRecord::create([
            'vehicle_id' => $vehicle->id,
            'item_type' => $type,
            'performed_at' => $performedAt->toDateString(),
            'odometer_km' => max(0, $odometer),
            'workshop' => $workshop,
            'notes' => $notes ?? ($type === MaintenanceItemType::Service ? 'Engine oil, oil filter and air filter changed.' : 'Brake pads checked, tyres rotated and balanced.'),
            'proof_path' => "maintenance-proofs/{$vehicle->id}/".$performedAt->format('Ymd').'-receipt.jpg',
            'proof_mime_type' => 'image/jpeg',
            'status' => $status,
            'submitted_by' => $vehicle->driver?->user_id,
            'reviewed_by' => $status === MaintenanceRecordStatus::Pending ? null : $reviewer?->id,
            'reviewed_at' => $status === MaintenanceRecordStatus::Pending ? null : $performedAt->copy()->addDay(),
            'rejection_reason' => $rejection,
        ]);
    }

    private function expire(Vehicle $vehicle, DocumentType $type, Carbon $expiresAt): void
    {
        DriverDocument::where('driver_id', $vehicle->driver_id)->where('type', $type)->update(['expires_at' => $expiresAt]);
    }

    /**
     * @param  Collection<int, Driver>  $drivers
     */
    private function repairs(Collection $drivers): void
    {
        $requests = [
            [RepairServiceType::Repair, RepairRequestStatus::InProgress, 'Brakes squeaking', 'Loud squeak from the front left wheel when braking, worse in the morning.', 2, 'Morning'],
            [RepairServiceType::Maintenance, RepairRequestStatus::Scheduled, 'Routine servicing', 'Due for the 5,000 km service. Also please check the AC gas.', 5, 'Afternoon'],
            [RepairServiceType::Repair, RepairRequestStatus::Completed, 'AC not cooling', 'AC blows warm air after 20 minutes of driving.', 12, 'Morning'],
        ];

        foreach ($requests as $n => [$type, $status, $title, $details, $daysAgo, $time]) {
            $driver = $drivers[$n];
            $submitted = now()->subDays($daysAgo);
            // A booked visit is still to come; the others were two days in.
            $visit = $status === RepairRequestStatus::Scheduled ? now()->addDays(2) : $submitted->copy()->addDays(2);

            $steps = [
                ['title' => 'Request submitted', 'subtitle' => $submitted->format('j M Y')." · {$type->label()} received", 'state' => 'completed'],
                ['title' => 'Technician assigned', 'subtitle' => $status === RepairRequestStatus::InProgress ? 'Your request is being coordinated' : 'Technician: Kunle (Autofix Garage)', 'state' => $status === RepairRequestStatus::InProgress ? 'in_progress' : 'completed'],
                ['title' => 'Workshop visit', 'subtitle' => $visit->format('j M Y')." · {$time}", 'state' => match ($status) {
                    RepairRequestStatus::Completed => 'completed',
                    RepairRequestStatus::Scheduled => 'in_progress',
                    default => 'upcoming',
                }],
                ['title' => 'Inspection complete', 'subtitle' => $status === RepairRequestStatus::Completed ? 'Compressor relay replaced and AC regassed.' : 'Report will be available once completed', 'state' => $status === RepairRequestStatus::Completed ? 'completed' : 'upcoming'],
            ];

            DriverRepair::create([
                'reference_number' => DriverRepair::generateReferenceNumber(),
                'driver_id' => $driver->user_id,
                'vehicle_id' => $driver->vehicle?->id,
                'type' => $type,
                'status' => $status,
                'title' => $title,
                'details' => $details,
                'preferred_date' => $visit->toDateString(),
                'preferred_time' => $time,
                'summary_note' => match ($status) {
                    RepairRequestStatus::Completed => 'Repair completed · AC working normally',
                    RepairRequestStatus::Scheduled => 'Workshop visit booked for '.$visit->format('j M').", {$time}",
                    default => "Request received · Workshop visit to be scheduled for {$time}",
                },
                'workshop_visit' => [
                    'scheduled_time' => $time,
                    'instructions' => $status === RepairRequestStatus::InProgress
                        ? 'A technician is being assigned for your vehicle. Contact fleet support for workshop details.'
                        : 'Bring the car to Autofix Garage, 14 Ogba Road, Ogba. Ask for Kunle.',
                    'support_phone' => '0812 5690 6790',
                ],
                'progress_steps' => $steps,
                'attachments' => [],
            ])->forceFill(['created_at' => $submitted, 'updated_at' => $status === RepairRequestStatus::Completed ? $visit : $submitted])->saveQuietly();
        }
    }
}
