<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\FuelType;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The approval history on the admin driver page: reviews, suspensions,
 * city moves, vehicle class and fuel changes and company car toggles,
 * newest first.
 */
class DriverHistoryService
{
    public const LIMIT = 50;

    private const DRIVER_EVENTS = [
        'application_submitted' => 'Application submitted',
        'driver_approved' => 'Approved',
        'driver_rejected' => 'Rejected',
        'driver_suspended' => 'Suspended',
        'driver_reactivated' => 'Reactivated',
        'driver_onboarding_and_kyc_completed_by_admin' => 'Onboarding and KYC completed by an admin',
        'driver_city_changed' => 'City changed',
    ];

    private const VEHICLE_EVENTS = [
        'vehicle_class_changed' => 'Vehicle class changed',
        'vehicle_fuel_type_changed' => 'Fuel type changed',
        'vehicle_marked_fleet' => 'Marked as a company car',
        'vehicle_unmarked_fleet' => 'Unmarked as a company car',
    ];

    private const DOCUMENT_EVENTS = [
        'document_approved' => 'Document approved',
        'document_rejected' => 'Document rejected',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Driver $driver): array
    {
        $logs = AuditLog::query()
            ->where(function (Builder $q) use ($driver) {
                $q->where(fn (Builder $q) => $q->where('auditable_type', $driver->getMorphClass())
                    ->where('auditable_id', $driver->id)
                    ->whereIn('event', array_keys(self::DRIVER_EVENTS)))
                    ->orWhere(fn (Builder $q) => $this->vehicleEntries($q, $driver))
                    ->orWhere(fn (Builder $q) => $this->documentEntries($q, $driver));
            })
            ->with('actor')
            // Ids are time-ordered, which settles entries made in the same second.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $cities = City::whereIn('id', $this->valuesOf($logs, 'city_id'))->pluck('name', 'id');
        $classes = VehicleClass::whereIn('id', $this->valuesOf($logs, 'vehicle_class_id'))->pluck('name', 'id');
        $documents = DriverDocument::whereIn('id', $logs->where('auditable_type', (new DriverDocument)->getMorphClass())->pluck('auditable_id'))
            ->pluck('type', 'id');

        return $logs->map(function (AuditLog $log) use ($cities, $classes, $documents) {
            [$old, $new] = $this->changes($log);
            $old = $this->present($old, $cities, $classes);
            $new = $this->present($new, $cities, $classes);

            $document = null;
            if (isset(self::DOCUMENT_EVENTS[$log->event])) {
                $type = $documents->get($log->auditable_id) ?? DocumentType::tryFrom($log->new_values['type'] ?? '');
                $document = [
                    'id' => $log->auditable_id,
                    'type' => $type?->value,
                    'type_label' => $type?->label(),
                ];
            }

            return [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $this->describe($log->event, $old, $new, $document),
                'old' => $old,
                'new' => $new,
                'reason' => $log->new_values['reason'] ?? $log->new_values['rejection_reason'] ?? $log->new_values['notes'] ?? null,
                'document' => $document,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => trim($log->actor->first_name.' '.$log->actor->last_name),
                    'role' => $log->actor->admin_role?->value ?? $log->actor->type?->value,
                ] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Vehicle changes are logged against the car, and shared fleet cars pass
     * between drivers, so each entry names the driver it was made for. Older
     * entries without one count for the car the driver has now.
     */
    private function vehicleEntries(Builder $q, Driver $driver): void
    {
        $vehicleIds = Vehicle::where('driver_id', $driver->id)->pluck('id');

        $q->where('auditable_type', (new Vehicle)->getMorphClass())
            ->whereIn('event', array_keys(self::VEHICLE_EVENTS))
            ->where(fn (Builder $q) => $q->where('new_values->driver_id', $driver->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('new_values->driver_id')->whereIn('auditable_id', $vehicleIds)));
    }

    /**
     * An approved renewal deletes the paper it replaces, so newer entries
     * also carry the driver.
     */
    private function documentEntries(Builder $q, Driver $driver): void
    {
        $documentIds = DriverDocument::where('driver_id', $driver->id)->pluck('id');

        $q->where('auditable_type', (new DriverDocument)->getMorphClass())
            ->whereIn('event', array_keys(self::DOCUMENT_EVENTS))
            ->where(fn (Builder $q) => $q->where('new_values->driver_id', $driver->id)
                ->orWhereIn('auditable_id', $documentIds));
    }

    /**
     * The recorded values, filled in from the event itself where an entry
     * did not record them (each of these only happens from one state).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function changes(AuditLog $log): array
    {
        [$old, $new] = match ($log->event) {
            'application_submitted' => [[], ['status' => 'pending_review']],
            'driver_approved' => [['status' => 'pending_review'], ['status' => 'approved']],
            'driver_rejected' => [['status' => 'pending_review'], ['status' => 'rejected']],
            'driver_suspended' => [['status' => 'approved'], ['status' => 'suspended']],
            'driver_reactivated' => [['status' => 'suspended'], ['status' => 'approved']],
            'vehicle_marked_fleet' => [['is_fleet' => false], ['is_fleet' => true]],
            'vehicle_unmarked_fleet' => [['is_fleet' => true], ['is_fleet' => false]],
            'document_approved' => [['status' => 'pending'], ['status' => 'approved']],
            'document_rejected' => [['status' => 'pending'], ['status' => 'rejected']],
            default => [[], []],
        };

        return [($log->old_values ?? []) + $old, ($log->new_values ?? []) + $new];
    }

    /**
     * Keeps the values the history shows, with cities and vehicle classes
     * named.
     *
     * @param  array<string, mixed>  $values
     * @param  Collection<string, string>  $cities
     * @param  Collection<string, string>  $classes
     * @return array<string, mixed>|null
     */
    private function present(array $values, Collection $cities, Collection $classes): ?array
    {
        $shown = array_intersect_key($values, array_flip(['status', 'kyc_status', 'fuel_type', 'is_fleet']));

        if (array_key_exists('city_id', $values)) {
            $shown['city'] = $values['city_id'] === null ? null : [
                'id' => $values['city_id'],
                'name' => $cities->get($values['city_id']),
            ];
        }

        if (array_key_exists('vehicle_class_id', $values)) {
            $shown['vehicle_class'] = $values['vehicle_class_id'] === null ? null : [
                'id' => $values['vehicle_class_id'],
                'name' => $classes->get($values['vehicle_class_id']),
            ];
        }

        return $shown === [] ? null : $shown;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>|null  $document
     */
    private function describe(string $event, ?array $old, ?array $new, ?array $document): string
    {
        $from = fn (string $what) => isset($old[$what]['name']) ? " from {$old[$what]['name']}" : '';
        $fuel = fn (?array $values) => FuelType::tryFrom($values['fuel_type'] ?? '')?->label();

        return match (true) {
            $event === 'driver_city_changed' && isset($new['city']['name']) => 'City changed'.$from('city')." to {$new['city']['name']}",
            $event === 'vehicle_class_changed' && isset($new['vehicle_class']['name']) => 'Vehicle class changed'.$from('vehicle_class')." to {$new['vehicle_class']['name']}",
            $event === 'vehicle_fuel_type_changed' && $fuel($new) !== null => 'Fuel type changed'.($fuel($old) !== null ? " from {$fuel($old)}" : '')." to {$fuel($new)}",
            isset(self::DOCUMENT_EVENTS[$event]) && isset($document['type_label']) => $document['type_label'].($event === 'document_approved' ? ' approved' : ' rejected'),
            default => self::DRIVER_EVENTS[$event] ?? self::VEHICLE_EVENTS[$event] ?? self::DOCUMENT_EVENTS[$event],
        };
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return list<string>
     */
    private function valuesOf(Collection $logs, string $key): array
    {
        return $logs->flatMap(fn (AuditLog $log) => [$log->old_values[$key] ?? null, $log->new_values[$key] ?? null])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
