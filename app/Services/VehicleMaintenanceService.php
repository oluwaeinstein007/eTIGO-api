<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\MaintenanceItemStatus;
use App\Enums\MaintenanceItemType;
use App\Enums\MaintenanceRecordStatus;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceItem;
use App\Models\VehicleMaintenanceRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle maintenance (Phase 2, OQ-19): every car is checked for routine
 * service, tyres & brakes and its legal papers; plug-in cars also for
 * battery health. Records belong to the vehicle, so they stay with a car
 * that changes hands.
 *
 * Overdue maintenance only warns. Expired insurance, roadworthiness or
 * vehicle licence keeps the car off the road once the grace period is over:
 * its driver cannot go online and gets no new offers until a new paper is
 * approved. A trip in progress is never ended.
 */
class VehicleMaintenanceService
{
    public const TIMEZONE = 'Africa/Lagos';

    public function __construct(
        private NotificationService $notifications,
        private OperationsAlertService $operations,
    ) {}

    /**
     * Create the items the vehicle's fuel type needs, archive the ones it
     * no longer needs, then recompute their state.
     */
    public function syncItems(Vehicle $vehicle): void
    {
        $this->ensureItems($vehicle);
        $this->refresh($vehicle);
    }

    public function ensureItems(Vehicle $vehicle): void
    {
        $existing = VehicleMaintenanceItem::where('vehicle_id', $vehicle->id)->get()->keyBy(fn ($item) => $item->type->value);
        $missing = [];

        foreach (MaintenanceItemType::cases() as $type) {
            $applies = $type->appliesTo($vehicle->fuel_type);
            $item = $existing->get($type->value);

            if ($applies && $item === null) {
                $missing[] = [
                    'id' => (string) Str::uuid7(),
                    'vehicle_id' => $vehicle->id,
                    'type' => $type->value,
                    'status' => MaintenanceItemStatus::Unknown->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } elseif ($applies && $item->archived_at !== null) {
                $item->update(['archived_at' => null]);
            } elseif (! $applies && $item !== null && $item->archived_at === null) {
                $item->update(['archived_at' => now(), 'blocks_driving' => false]);
            }
        }

        if ($missing !== []) {
            // One insert; a concurrent run that got there first is ignored (unique vehicle + type).
            VehicleMaintenanceItem::insertOrIgnore($missing);
        }
    }

    /**
     * Recompute each item's due date and state, and start or lift the
     * expired-papers block. A block that starts takes the driver offline
     * (unless on a trip) and releases their reserved scheduled pickups.
     *
     * @return Collection<int, VehicleMaintenanceItem> the vehicle's current items
     */
    public function refresh(Vehicle $vehicle): Collection
    {
        $settings = AppSetting::vehicleMaintenance();
        $today = $this->today();

        $items = VehicleMaintenanceItem::where('vehicle_id', $vehicle->id)->current()->get();

        if ($items->isEmpty()) {
            $this->ensureItems($vehicle);
            $items = VehicleMaintenanceItem::where('vehicle_id', $vehicle->id)->current()->get();
        }

        foreach ($items as $item) {
            $item->fill($item->type->isLegalPaper()
                ? $this->legalPaperState($vehicle, $item->type, $settings, $today)
                : $this->intervalState($vehicle, $item->type, $settings, $today));

            if ($item->isDirty()) {
                $item->save();
            }
        }

        $this->updateBlock($vehicle, $items);

        return $items->sortBy(fn (VehicleMaintenanceItem $item) => array_search($item->type, MaintenanceItemType::cases(), true))->values();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function legalPaperState(Vehicle $vehicle, MaintenanceItemType $type, array $settings, Carbon $today): array
    {
        $document = $this->currentDocument($vehicle, $type);
        $dueAt = $this->localDate($document?->expires_at);

        $status = match (true) {
            $dueAt === null => MaintenanceItemStatus::Unknown,
            $dueAt->lt($today) => MaintenanceItemStatus::Expired,
            $dueAt->lte($today->copy()->addDays($settings['due_soon_days'])) => MaintenanceItemStatus::DueSoon,
            default => MaintenanceItemStatus::Ok,
        };

        $blocks = $settings['enabled']
            && $settings['block_on_expired_papers']
            && $status === MaintenanceItemStatus::Expired
            && $today->gte($this->blocksFrom($dueAt, $settings));

        return [
            'status' => $status,
            'due_at' => $dueAt?->toDateString(),
            'due_odometer_km' => null,
            'last_done_at' => ($document?->reviewed_at ?? $document?->created_at)?->copy()->setTimezone(self::TIMEZONE)->toDateString(),
            'last_odometer_km' => null,
            'blocks_driving' => $blocks,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function intervalState(Vehicle $vehicle, MaintenanceItemType $type, array $settings, Carbon $today): array
    {
        $record = VehicleMaintenanceRecord::where('vehicle_id', $vehicle->id)
            ->where('item_type', $type)
            ->approved()
            ->orderByDesc('performed_at')
            ->orderByDesc('odometer_km')
            ->first();

        $interval = $this->interval($vehicle, $type, $settings);
        $lastDoneAt = $this->localDate($record?->performed_at);
        $lastOdometer = $record?->odometer_km;

        $dueAt = $lastDoneAt !== null && $interval['months'] !== null
            ? $lastDoneAt->copy()->addMonthsNoOverflow($interval['months'])
            : null;
        $dueOdometer = $lastOdometer !== null && $interval['km'] !== null
            ? $lastOdometer + $interval['km']
            : null;
        $odometer = $vehicle->estimatedOdometerKm();

        $overdue = ($dueAt !== null && $dueAt->lt($today))
            || ($dueOdometer !== null && $odometer !== null && $odometer >= $dueOdometer);
        $dueSoon = ($dueAt !== null && $dueAt->lte($today->copy()->addDays($settings['due_soon_days'])))
            || ($dueOdometer !== null && $odometer !== null && $odometer >= $dueOdometer - $settings['due_soon_km']);

        $status = match (true) {
            $lastDoneAt === null => MaintenanceItemStatus::Unknown,
            $overdue => MaintenanceItemStatus::Overdue,
            $dueSoon => MaintenanceItemStatus::DueSoon,
            default => MaintenanceItemStatus::Ok,
        };

        return [
            'status' => $status,
            'due_at' => $dueAt?->toDateString(),
            'due_odometer_km' => $dueOdometer,
            'last_done_at' => $lastDoneAt?->toDateString(),
            'last_odometer_km' => $lastOdometer,
            'blocks_driving' => false,
        ];
    }

    /**
     * The vehicle's best approved paper of this type: the one valid longest.
     */
    public function currentDocument(Vehicle $vehicle, MaintenanceItemType $type): ?DriverDocument
    {
        $documentType = $type->documentType();

        if ($documentType === null) {
            return null;
        }

        return DriverDocument::where('vehicle_id', $vehicle->id)
            ->where('type', $documentType)
            ->where('status', DocumentStatus::Approved)
            ->orderByRaw('expires_at desc nulls last')
            ->orderByDesc('reviewed_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{km: ?int, months: ?int}
     */
    public function interval(Vehicle $vehicle, MaintenanceItemType $type, array $settings): array
    {
        $fuelType = $vehicle->fuel_type?->value;

        return $settings['fuel_type_intervals'][$fuelType][$type->value]
            ?? $settings['intervals'][$type->value]
            ?? ['km' => null, 'months' => null];
    }

    /**
     * The first day an expired paper keeps the car off the road.
     *
     * @param  array<string, mixed>  $settings
     */
    public function blocksFrom(Carbon $expiresAt, array $settings): Carbon
    {
        return $this->localDate($expiresAt)->addDays($settings['legal_grace_days'] + 1);
    }

    public function today(): Carbon
    {
        return now()->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /**
     * A calendar date (expiry, service date) as the start of that day in
     * Lagos, so it compares cleanly with today().
     */
    public function localDate(?Carbon $date): ?Carbon
    {
        return $date === null ? null : Carbon::parse($date->toDateString(), self::TIMEZONE)->startOfDay();
    }

    /**
     * @param  Collection<int, VehicleMaintenanceItem>  $items
     */
    private function updateBlock(Vehicle $vehicle, Collection $items): void
    {
        $blocking = $items->filter(fn (VehicleMaintenanceItem $item) => $item->blocks_driving);

        if ($blocking->isNotEmpty()) {
            $reason = $blocking
                ->map(fn (VehicleMaintenanceItem $item) => "{$item->type->label()} expired on {$item->due_at->format('j M Y')}.")
                ->implode(' ');

            if ($vehicle->blocked_at === null) {
                $vehicle->forceFill(['blocked_at' => now(), 'block_reason' => $reason])->saveQuietly();
                AuditLog::record($vehicle, 'vehicle_blocked', null, null, ['reason' => $reason]);
                DB::afterCommit(fn () => $this->blockStarted($vehicle->fresh()));
            } elseif ($vehicle->block_reason !== $reason) {
                $vehicle->forceFill(['block_reason' => $reason])->saveQuietly();
            }

            return;
        }

        if ($vehicle->blocked_at !== null) {
            $vehicle->forceFill(['blocked_at' => null, 'block_reason' => null])->saveQuietly();
            AuditLog::record($vehicle, 'vehicle_unblocked');
            DB::afterCommit(fn () => $this->blockLifted($vehicle->fresh()));
        }
    }

    /**
     * The block has just started: tell the driver, take them offline unless
     * they are on a trip (they finish it; matching skips them afterwards),
     * release their reserved pickups so the bookings go back to the pool in
     * time, and tell operations about fleet cars.
     */
    private function blockStarted(Vehicle $vehicle): void
    {
        $driver = $vehicle->driver()->with('user')->first();

        if ($driver !== null) {
            $this->safely(fn () => $this->notifications->sendCritical(
                userId: $driver->user_id,
                type: NotificationType::VehicleBlocked,
                title: NotificationType::VehicleBlocked->label(),
                body: "{$vehicle->block_reason} Upload the renewed paper; you can go online again once E-tiGo approves it.",
                data: ['vehicle_id' => (string) $vehicle->id],
            ));

            $this->safely(fn () => $this->takeOfflineUnlessOnTrip($driver));
            $this->safely(fn () => $this->releaseReservations($driver));
        }

        if ($vehicle->is_fleet) {
            $this->operations->send(
                label: 'Fleet car off the road',
                headline: "{$vehicle->plate_number} can't be driven",
                body: "{$vehicle->block_reason} Upload the renewed paper for the car to drive again.",
                lines: [],
                actionPath: "/admin/vehicles/{$vehicle->id}/maintenance",
                actionLabel: 'Open the car',
            );
        }
    }

    private function blockLifted(Vehicle $vehicle): void
    {
        $driver = $vehicle->driver;

        if ($driver === null) {
            return;
        }

        $this->safely(fn () => $this->notifications->send(
            userId: $driver->user_id,
            type: NotificationType::VehicleUnblocked,
            title: NotificationType::VehicleUnblocked->label(),
            body: "Your vehicle's papers are up to date. You can go online again.",
            data: ['vehicle_id' => (string) $vehicle->id],
        ));
    }

    private function takeOfflineUnlessOnTrip(Driver $driver): void
    {
        DB::transaction(function () use ($driver) {
            $driver = Driver::lockForUpdate()->find($driver->id);

            if ($driver === null || ! $driver->is_online) {
                return;
            }

            $onTrip = Ride::where('driver_id', $driver->user_id)->whereIn('status', RideStatus::driverOnTripStatuses())->exists();

            if ($onTrip) {
                return;
            }

            $driver->update(['is_online' => false]);
            AuditLog::record($driver, 'driver_set_offline_vehicle_blocked');
        });

        try {
            app(DriverLocationService::class)->removeDriver($driver->id, $driver->user_id);
        } catch (\Throwable $e) {
            Log::warning('Failed to remove blocked driver location', ['driver_id' => $driver->id, 'error' => $e->getMessage()]);
        }
    }

    private function releaseReservations(Driver $driver): void
    {
        $reservations = app(ScheduledRideReservationService::class);

        ScheduledRide::query()
            ->upcoming()
            ->where('reserved_driver_id', $driver->user_id)
            ->pluck('ride_id')
            ->each(fn (string $rideId) => DB::transaction(function () use ($rideId, $driver, $reservations) {
                $ride = Ride::lockForUpdate()->find($rideId);
                $scheduled = ScheduledRide::where('ride_id', $rideId)->lockForUpdate()->first();

                if ($ride === null || $scheduled === null || ! $scheduled->isUpcoming() || $scheduled->reserved_driver_id !== $driver->user_id) {
                    return;
                }

                $reservations->clearReservation($ride, $scheduled, 'vehicle_blocked');
            }));
    }

    /**
     * Tell the driver about items that have become due, overdue, expired or
     * are still missing a record, once per change (one message per car), and
     * collect fleet-car items for the operations digest. Daily job only.
     *
     * @param  Collection<int, VehicleMaintenanceItem>  $items
     * @return list<string> lines for the operations digest
     */
    public function sendReminders(Vehicle $vehicle, Collection $items): array
    {
        $settings = AppSetting::vehicleMaintenance();
        $changed = $items->filter(fn (VehicleMaintenanceItem $item) => $item->status !== $item->notified_status);

        if ($changed->isEmpty()) {
            return [];
        }

        $toTell = $changed->filter(fn (VehicleMaintenanceItem $item) => $item->status->needsAttention());

        if ($settings['enabled'] && $toTell->isNotEmpty() && $vehicle->driver !== null) {
            $this->safely(fn () => $this->notifications->send(
                userId: $vehicle->driver->user_id,
                type: NotificationType::VehicleMaintenanceDue,
                title: NotificationType::VehicleMaintenanceDue->label(),
                body: $toTell->map(fn (VehicleMaintenanceItem $item) => $this->reminderLine($item, $settings))->implode(' '),
                data: ['vehicle_id' => (string) $vehicle->id],
            ));
        }

        foreach ($changed as $item) {
            $item->forceFill([
                'notified_status' => $item->status->needsAttention() ? $item->status : null,
                'notified_at' => now(),
            ])->save();
        }

        if (! $settings['enabled'] || ! $vehicle->is_fleet) {
            return [];
        }

        return $changed
            ->filter(fn (VehicleMaintenanceItem $item) => in_array($item->status, [MaintenanceItemStatus::Overdue, MaintenanceItemStatus::Expired], true))
            ->map(fn (VehicleMaintenanceItem $item) => "{$vehicle->plate_number}: {$this->reminderLine($item, $settings)}")
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function reminderLine(VehicleMaintenanceItem $item, array $settings): string
    {
        $label = $item->type->label();
        $date = $item->due_at?->format('j M Y');

        return match ($item->status) {
            MaintenanceItemStatus::DueSoon => $item->type->isLegalPaper()
                ? "{$label} expires on {$date}; upload the renewed paper."
                : "{$label} is due".($date ? " by {$date}" : '').($item->due_odometer_km ? " or at {$item->due_odometer_km} km" : '').'.',
            MaintenanceItemStatus::Overdue => "{$label} is overdue.",
            MaintenanceItemStatus::Expired => $item->blocks_driving
                ? "{$label} expired on {$date}; you can't go online until a renewed paper is approved."
                : "{$label} expired on {$date}; upload the renewed paper by {$this->blocksFrom($item->due_at, $settings)->subDay()->format('j M Y')} to keep driving.",
            MaintenanceItemStatus::Unknown => $item->type->isLegalPaper()
                ? "Upload your vehicle's {$label} with its expiry date."
                : "Add the date of your last {$label}.",
            MaintenanceItemStatus::Ok => "{$label} is up to date.",
        };
    }

    /**
     * What the driver should know when going online: why the car is off the
     * road, or a warning about papers in their grace period or expiring soon.
     *
     * @return array{blocked: ?string, notice: ?string}
     */
    public function goOnlineCheck(Vehicle $vehicle): array
    {
        $items = $this->refresh($vehicle);
        $vehicle->refresh();

        if ($vehicle->isBlocked()) {
            return [
                'blocked' => "{$vehicle->block_reason} Upload the renewed paper; you can go online once E-tiGo approves it.",
                'notice' => null,
            ];
        }

        $settings = AppSetting::vehicleMaintenance();
        $warnings = $items
            ->filter(fn (VehicleMaintenanceItem $item) => $item->type->isLegalPaper()
                && in_array($item->status, [MaintenanceItemStatus::Expired, MaintenanceItemStatus::DueSoon], true))
            ->map(fn (VehicleMaintenanceItem $item) => $this->reminderLine($item, $settings));

        return [
            'blocked' => null,
            'notice' => $settings['enabled'] && $warnings->isNotEmpty() ? $warnings->implode(' ') : null,
        ];
    }

    /**
     * Log work done on the vehicle: by operations (approved at once) or by
     * the driver with proof (pending until operations approve it).
     *
     * @param  array{item_type: string, performed_at: string, odometer_km?: ?int, workshop?: ?string, notes?: ?string, driver_repair_id?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function submitRecord(Vehicle $vehicle, User $submitter, array $data, ?UploadedFile $proof, bool $byAdmin): VehicleMaintenanceRecord
    {
        $type = MaintenanceItemType::from($data['item_type']);
        $performedAt = Carbon::parse($data['performed_at'], self::TIMEZONE)->startOfDay();
        $odometer = isset($data['odometer_km']) ? (int) $data['odometer_km'] : null;

        if (! $type->appliesTo($vehicle->fuel_type)) {
            throw ValidationException::withMessages(['item_type' => ["{$type->label()} only applies to electric and plug-in hybrid vehicles."]]);
        }

        if ($type === MaintenanceItemType::Service && $odometer === null) {
            throw ValidationException::withMessages(['odometer_km' => ['Give the odometer reading at the service, so the next one can be worked out.']]);
        }

        $path = $proof?->store("vehicle-maintenance/{$vehicle->id}", config('filesystems.uploads'));

        if ($proof !== null && ! $path) {
            throw ValidationException::withMessages(['proof' => ['The file could not be uploaded. Please try again.']]);
        }

        try {
            $record = DB::transaction(function () use ($vehicle, $submitter, $data, $type, $performedAt, $odometer, $proof, $path, $byAdmin) {
                $vehicle = Vehicle::lockForUpdate()->findOrFail($vehicle->id);

                if ($odometer !== null) {
                    $this->assertOdometerFits($vehicle, $performedAt, $odometer);
                }

                $record = VehicleMaintenanceRecord::create([
                    'vehicle_id' => $vehicle->id,
                    'item_type' => $type,
                    'performed_at' => $performedAt->toDateString(),
                    'odometer_km' => $odometer,
                    'workshop' => $data['workshop'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'proof_path' => $path ?: null,
                    'proof_mime_type' => $proof?->getMimeType(),
                    'driver_repair_id' => $data['driver_repair_id'] ?? null,
                    'status' => $byAdmin ? MaintenanceRecordStatus::Approved : MaintenanceRecordStatus::Pending,
                    'submitted_by' => $submitter->id,
                    'reviewed_by' => $byAdmin ? $submitter->id : null,
                    'reviewed_at' => $byAdmin ? now() : null,
                ]);

                AuditLog::record($record, $byAdmin ? 'maintenance_record_added' : 'maintenance_record_submitted', $submitter);

                if ($byAdmin) {
                    $this->applyApprovedRecord($vehicle, $record);
                }

                return $record;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk(config('filesystems.uploads'))->delete($path);
            }

            throw $e;
        }

        return $record;
    }

    /**
     * @throws ValidationException
     */
    public function reviewRecord(VehicleMaintenanceRecord $record, User $admin, bool $approve, ?string $reason): VehicleMaintenanceRecord
    {
        $record = DB::transaction(function () use ($record, $admin, $approve, $reason) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($record->vehicle_id);
            $record = VehicleMaintenanceRecord::lockForUpdate()->findOrFail($record->id);

            if ($record->status !== MaintenanceRecordStatus::Pending) {
                throw ValidationException::withMessages(['record' => ['Only records awaiting review can be approved or rejected.']]);
            }

            if ($approve && $record->odometer_km !== null) {
                $this->assertOdometerFits($vehicle, $record->performed_at, $record->odometer_km);
            }

            $record->update([
                'status' => $approve ? MaintenanceRecordStatus::Approved : MaintenanceRecordStatus::Rejected,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $approve ? null : $reason,
            ]);

            AuditLog::record($record, $approve ? 'maintenance_record_approved' : 'maintenance_record_rejected', $admin, null, ['reason' => $reason]);

            if ($approve) {
                $this->applyApprovedRecord($vehicle, $record);
            }

            return $record;
        });

        if ($record->submitted_by !== null && $record->submitted_by !== $admin->id) {
            $label = $record->item_type->label();

            $this->safely(fn () => $this->notifications->send(
                userId: $record->submitted_by,
                type: NotificationType::MaintenanceRecordReviewed,
                title: NotificationType::MaintenanceRecordReviewed->label(),
                body: $approve
                    ? "Your {$label} record was approved."
                    : "Your {$label} record was not accepted".($reason ? ": {$reason}" : '.').' You can submit it again.',
                data: ['vehicle_id' => (string) $record->vehicle_id, 'record_id' => (string) $record->id],
            ));
        }

        return $record;
    }

    /**
     * A new odometer reading from the driver. Readings never go down.
     *
     * @throws ValidationException
     */
    public function recordOdometer(Vehicle $vehicle, int $odometerKm, User $actor): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $odometerKm, $actor) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($vehicle->id);
            $this->assertOdometerFits($vehicle, $this->today(), $odometerKm);

            $old = $vehicle->odometer_km;
            $this->setOdometer($vehicle, $odometerKm, now());
            AuditLog::record($vehicle, 'odometer_recorded', $actor, ['odometer_km' => $old], ['odometer_km' => $odometerKm]);
            $this->refresh($vehicle);

            return $vehicle->fresh();
        });
    }

    /**
     * Trip distance counts towards the km-based service interval until the
     * next odometer reading.
     */
    public function addTripDistance(string $driverUserId, float $distanceKm): void
    {
        if ($distanceKm <= 0) {
            return;
        }

        Vehicle::query()
            ->whereHas('driver', fn ($q) => $q->where('user_id', $driverUserId))
            ->increment('trip_km_since_odometer', round($distanceKm, 2));
    }

    /**
     * The approved paper's expiry or the record's date and reading now
     * count. Must run inside the transaction holding the vehicle lock.
     */
    private function applyApprovedRecord(Vehicle $vehicle, VehicleMaintenanceRecord $record): void
    {
        $readAt = $this->localDate($record->performed_at)->endOfDay()->min(now());

        if ($record->odometer_km !== null && ($vehicle->odometer_read_at === null || $readAt->gte($vehicle->odometer_read_at))) {
            $this->setOdometer($vehicle, $record->odometer_km, $readAt);
        }

        $this->refresh($vehicle);
    }

    private function setOdometer(Vehicle $vehicle, int $odometerKm, Carbon $readAt): void
    {
        $vehicle->forceFill([
            'odometer_km' => $odometerKm,
            'odometer_read_at' => $readAt,
            'trip_km_since_odometer' => 0,
        ])->saveQuietly();
    }

    /**
     * An odometer reading must not be lower than one taken earlier, nor
     * higher than one taken later (a likely typo either way).
     *
     * @throws ValidationException
     */
    private function assertOdometerFits(Vehicle $vehicle, Carbon $at, int $odometerKm): void
    {
        $day = $at->copy()->setTimezone(self::TIMEZONE)->startOfDay();
        $records = VehicleMaintenanceRecord::where('vehicle_id', $vehicle->id)->approved()->whereNotNull('odometer_km');

        $earlier = (clone $records)->whereDate('performed_at', '<=', $day->toDateString())->max('odometer_km');
        $later = (clone $records)->whereDate('performed_at', '>', $day->toDateString())->min('odometer_km');

        if ($vehicle->odometer_km !== null && $vehicle->odometer_read_at !== null) {
            $readDay = $vehicle->odometer_read_at->copy()->setTimezone(self::TIMEZONE)->startOfDay();

            if ($readDay->lte($day)) {
                $earlier = max((int) $earlier, $vehicle->odometer_km);
            } else {
                $later = $later === null ? $vehicle->odometer_km : min((int) $later, $vehicle->odometer_km);
            }
        }

        if ($earlier !== null && $odometerKm < (int) $earlier) {
            throw ValidationException::withMessages(['odometer_km' => ["The reading is lower than the last one ({$earlier} km). Check the number on the dashboard."]]);
        }

        if ($later !== null && $odometerKm > (int) $later) {
            throw ValidationException::withMessages(['odometer_km' => ["The reading is higher than a later one ({$later} km). Check the date and the number."]]);
        }
    }

    private function safely(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('Vehicle maintenance side effect failed', ['error' => $e->getMessage()]);
        }
    }
}
