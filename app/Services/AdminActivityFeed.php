<?php

namespace App\Services;

use App\Models\AdminInvitation;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Dispute;
use App\Models\Driver;
use App\Models\EvChargingStation;
use App\Models\OfflineTripFlag;
use App\Models\PricingConfig;
use App\Models\PromoCode;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\SurgeRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Recent activity" on the admin overview: a curated slice of the audit log.
 *
 * Only events someone running operations acts on are listed, and only those
 * from parts of the console the admin's role can open. Per-trip events (ride
 * completed, ride created) are left out on purpose: at a few thousand a day
 * they would bury everything else, and the overview's cards already count them.
 */
class AdminActivityFeed
{
    /**
     * event => [area, category, title]. The category is for the icon.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const EVENTS = [
        'driver_approved' => [AdminAreaAccess::DRIVERS, 'driver', 'Driver verification approved'],
        'driver_rejected' => [AdminAreaAccess::DRIVERS, 'driver', 'Driver application rejected'],
        'driver_suspended' => [AdminAreaAccess::DRIVERS, 'driver', 'Driver suspended'],
        'driver_reactivated' => [AdminAreaAccess::DRIVERS, 'driver', 'Driver reactivated'],
        'dispute_filed' => [AdminAreaAccess::DISPUTES, 'dispute', 'New dispute filed'],
        'dispute_assigned' => [AdminAreaAccess::DISPUTES, 'dispute', 'Dispute assigned'],
        'dispute_resolved' => [AdminAreaAccess::DISPUTES, 'dispute', 'Dispute resolved'],
        'sos_triggered' => [AdminAreaAccess::SOS, 'safety', 'SOS raised'],
        'sos_resolved' => [AdminAreaAccess::SOS, 'safety', 'SOS resolved'],
        'offline_trip_flagged' => [AdminAreaAccess::OFFLINE_FLAGS, 'compliance', 'Offline trip flagged'],
        'ride_manually_assigned' => [AdminAreaAccess::MONITORING, 'ride', 'Ride assigned manually'],
        'pricing_config_created' => [AdminAreaAccess::PRICING, 'pricing', 'Pricing version published'],
        'surge_rule_created' => [AdminAreaAccess::SURGE, 'pricing', 'Surge rule created'],
        'promo_code_created' => [AdminAreaAccess::PROMOS, 'promo', 'Promo created'],
        'city_created' => [AdminAreaAccess::CITIES, 'city', 'City added'],
        'ev_station_created' => [AdminAreaAccess::EV_STATIONS, 'ev_station', 'EV station added'],
        'payout.approved' => [AdminAreaAccess::FINANCE, 'finance', 'Driver payout approved'],
        'adjustment.approved' => [AdminAreaAccess::FINANCE, 'finance', 'Wallet adjustment approved'],
        'commission.updated' => [AdminAreaAccess::FINANCE, 'finance', 'Commission rate updated'],
        'admin_invited' => [AdminAreaAccess::ADMINS, 'admin', 'Admin invited'],
    ];

    /** Audit rows read at most per request, however many get filtered out. */
    private const MAX_SCANNED = 300;

    public function __construct(
        private AdminAreaAccess $access,
        private DisputeAccess $disputeAccess,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(User $admin, int $limit): array
    {
        $events = array_keys(array_filter(
            self::EVENTS,
            fn (array $meta) => $this->access->can($admin, $meta[0]),
        ));

        if ($events === []) {
            return [];
        }

        // Dispute rows from queues the admin cannot see are dropped after
        // loading, so keep reading pages until the list is full.
        $items = [];
        $batch = max($limit * 3, 30);

        for ($offset = 0; count($items) < $limit && $offset < self::MAX_SCANNED; $offset += $batch) {
            $logs = AuditLog::query()
                ->whereIn('event', $events)
                ->with([
                    'actor',
                    'auditable' => fn (MorphTo $morph) => $morph->morphWith([
                        Driver::class => ['user', 'vehicle', 'city'],
                        PricingConfig::class => ['city', 'vehicleClass'],
                        SosIncident::class => ['ride'],
                        OfflineTripFlag::class => ['driver'],
                    ]),
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->offset($offset)
                ->limit($batch)
                ->get();

            $disputes = $this->disputesFor($logs);
            $incidents = $this->incidentsFor($logs);

            foreach ($logs as $log) {
                $item = $this->item($log, $admin, $disputes, $incidents);

                if ($item !== null) {
                    $items[] = $item;
                }
            }

            if ($logs->count() < $batch) {
                break;
            }
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * The disputes behind dispute events, keyed by id. dispute_filed is
     * logged against the ride, with the dispute in new_values.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return Collection<string, Dispute>
     */
    private function disputesFor(Collection $logs): Collection
    {
        $ids = $logs
            ->filter(fn (AuditLog $log) => self::EVENTS[$log->event][1] === 'dispute')
            ->map(fn (AuditLog $log) => $log->auditable instanceof Dispute ? $log->auditable->id : ($log->new_values['dispute_id'] ?? null))
            ->filter()
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : Dispute::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * The incidents behind sos_triggered, which is logged against the ride.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return Collection<string, SosIncident>
     */
    private function incidentsFor(Collection $logs): Collection
    {
        $ids = $logs
            ->where('event', 'sos_triggered')
            ->map(fn (AuditLog $log) => $log->new_values['incident_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : SosIncident::with('ride')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  Collection<string, Dispute>  $disputes
     * @param  Collection<string, SosIncident>  $incidents
     * @return array<string, mixed>|null null when the admin may not see it
     */
    private function item(AuditLog $log, User $admin, Collection $disputes, Collection $incidents): ?array
    {
        [, $category, $title] = self::EVENTS[$log->event];
        $subject = $log->auditable;

        if ($category === 'dispute') {
            $subject = $disputes->get($subject instanceof Dispute ? $subject->id : ($log->new_values['dispute_id'] ?? ''));

            if ($subject === null || ! $this->disputeAccess->canView($admin, $subject)) {
                return null;
            }

            if ($log->event === 'dispute_assigned' && ($log->new_values['assigned_admin_id'] ?? null) === $admin->id) {
                $title = 'Dispute assigned to you';
            }
        }

        if ($log->event === 'sos_triggered') {
            $subject = $incidents->get($log->new_values['incident_id'] ?? '') ?? $subject;
        }

        return [
            'id' => $log->id,
            'event' => $log->event,
            'category' => $category,
            'title' => $title,
            'subtitle' => $subject ? $this->subtitle($subject) : null,
            'subject' => $subject ? [
                'type' => $this->subjectType($subject),
                'id' => $subject->getKey(),
                'reference_number' => $subject->getAttribute('reference_number'),
            ] : null,
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'name' => $this->name($log->actor),
                'type' => $log->actor_type,
            ] : null,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function subtitle(Model $subject): ?string
    {
        $parts = match (true) {
            $subject instanceof Driver => [
                $subject->user ? $this->name($subject->user) : null,
                $subject->vehicle ? trim($subject->vehicle->make.' '.$subject->vehicle->model) : null,
                $subject->city?->name,
            ],
            $subject instanceof Dispute => [$subject->reference_number, $subject->category?->label()],
            $subject instanceof SosIncident => [$subject->reference_number, $subject->ride?->reference_number],
            $subject instanceof OfflineTripFlag => [$subject->reference_number, $subject->driver ? $this->name($subject->driver) : null],
            $subject instanceof Ride => [$subject->reference_number, $this->route($subject)],
            $subject instanceof PricingConfig => [
                $subject->city?->name,
                $subject->vehicleClass?->display_name ?? $subject->vehicleClass?->name,
                $subject->version ? 'v'.$subject->version : null,
            ],
            $subject instanceof SurgeRule, $subject instanceof City, $subject instanceof EvChargingStation => [$subject->name],
            $subject instanceof PromoCode => [$subject->code],
            $subject instanceof AdminInvitation => [$subject->email],
            default => [$subject->getAttribute('reference_number')],
        };

        $text = implode(' · ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));

        return $text === '' ? null : $text;
    }

    private function route(Ride $ride): ?string
    {
        $from = $this->place($ride->pickup_address);
        $to = $this->place($ride->destination_address);

        return $from && $to ? "{$from} → {$to}" : null;
    }

    /**
     * The first part of an address ("Bodija Market, Ibadan, Oyo" → "Bodija Market").
     */
    private function place(?string $address): ?string
    {
        $first = trim(explode(',', (string) $address)[0]);

        return $first === '' ? null : $first;
    }

    private function subjectType(Model $subject): string
    {
        return match (true) {
            $subject instanceof SosIncident => 'sos_incident',
            $subject instanceof OfflineTripFlag => 'offline_flag',
            $subject instanceof EvChargingStation => 'ev_station',
            $subject instanceof AdminInvitation => 'admin_invitation',
            default => Str::snake(class_basename($subject)),
        };
    }

    private function name(User $user): string
    {
        return trim($user->first_name.' '.$user->last_name);
    }
}
