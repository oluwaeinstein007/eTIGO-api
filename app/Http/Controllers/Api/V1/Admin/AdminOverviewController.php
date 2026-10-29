<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Enums\SanctionTier;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Dispute;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\OperationsSnapshot;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use App\Services\AdminActivityFeed;
use App\Services\AdminAreaAccess;
use App\Services\DisputeAccess;
use App\Services\DriverLocationService;
use App\Services\OperationsMetricsService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The admin console's landing page. Every admin can open it; each card is
 * null when the admin's role cannot open the screen behind it, so the page
 * never shows a number the admin cannot follow up on.
 */
class AdminOverviewController extends Controller
{
    /** Revenue "today" runs from midnight in Nigeria. */
    private const BUSINESS_TIMEZONE = 'Africa/Lagos';

    private const MAX_MARKERS = 300;

    public function __construct(
        private AdminAreaAccess $access,
        private DisputeAccess $disputeAccess,
        private OperationsMetricsService $metrics,
        private AdminActivityFeed $activity,
        private DriverLocationService $locations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'activity_limit' => ['sometimes', 'integer', 'between:1,20'],
        ]);

        $admin = $request->user();
        $can = fn (string $area) => $this->access->can($admin, $area);
        $live = $can(AdminAreaAccess::MONITORING) ? $this->liveCards() : null;

        return response()->json([
            'stats' => [
                'active_rides' => $live['active_rides'] ?? null,
                'drivers_online' => $live['drivers_online'] ?? null,
                'open_disputes' => $can(AdminAreaAccess::DISPUTES) ? $this->openDisputes($admin) : null,
                'revenue' => $can(AdminAreaAccess::REPORTS) ? $this->revenueToday() : null,
            ],
            'attention' => [
                'safety_alerts' => $can(AdminAreaAccess::SOS) ? [
                    'count' => SosIncident::active()->count(),
                    'critical' => SosIncident::requiringAttention()->count(),
                ] : null,
                'compliance_reviews' => $can(AdminAreaAccess::OFFLINE_FLAGS) ? [
                    'count' => OfflineTripFlag::active()->count(),
                    'high_severity' => OfflineTripFlag::active()->where('sanction_tier', SanctionTier::Deactivation)->count(),
                ] : null,
                'pending_kyc' => $can(AdminAreaAccess::DRIVERS) ? [
                    'count' => Driver::where('status', DriverStatus::PendingReview)->count(),
                ] : null,
                'unmatched_requests' => $can(AdminAreaAccess::MONITORING) ? [
                    'count' => Ride::awaitingManualAssignment()->count(),
                ] : null,
            ],
            'recent_activity' => $this->activity->recent($admin, $request->integer('activity_limit', 5)),
            'can_view_live_operations' => $can(AdminAreaAccess::MONITORING),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * The map and latest trips for one city. Defaults to the city with the
     * most active rides.
     */
    public function liveOperations(Request $request): JsonResponse
    {
        $request->validate([
            'city_id' => ['sometimes', 'uuid', 'exists:cities,id'],
            'rides_limit' => ['sometimes', 'integer', 'between:1,20'],
        ]);

        $byCity = $this->metrics->activeRides()
            ->selectRaw('city_id, COUNT(*) AS total')
            ->groupBy('city_id')
            ->pluck('total', 'city_id')
            ->map(fn ($total) => (int) $total)
            ->sortDesc();

        $cities = City::whereIn('id', $byCity->keys()->filter())->get(['id', 'name'])->keyBy('id');
        $cityList = $byCity
            ->filter(fn ($total, $cityId) => $cities->has($cityId))
            ->map(fn ($total, $cityId) => ['id' => $cityId, 'name' => $cities[$cityId]->name, 'active_rides' => $total])
            ->values();

        $city = $request->filled('city_id')
            ? City::find($request->input('city_id'), ['id', 'name'])
            : ($cityList->isNotEmpty() ? $cities[$cityList[0]['id']] : null);

        if ($city === null) {
            return response()->json([
                'city' => null,
                'cities' => $cityList,
                'summary' => ['active_rides' => 0, 'needs_attention' => 0],
                'markers' => [],
                'markers_truncated' => false,
                'rides' => [],
                'generated_at' => now()->toIso8601String(),
            ]);
        }

        $rides = $this->metrics->activeRides()
            ->where('city_id', $city->id)
            ->with(['passenger', 'driver.driver'])
            ->orderByDesc('created_at')
            ->limit(self::MAX_MARKERS + 1)
            ->get();

        $truncated = $rides->count() > self::MAX_MARKERS;
        $rides = $rides->take(self::MAX_MARKERS);

        $sosRideIds = SosIncident::active()
            ->whereIn('ride_id', $rides->pluck('id'))
            ->pluck('ride_id')
            ->flip();

        $positions = $this->driverPositions($rides);
        $staleBefore = now()->subMinutes((int) config('matching.silent_driver_warning_minutes', 5))->timestamp;

        $entries = $rides->map(function (Ride $ride) use ($sosRideIds, $positions, $staleBefore) {
            $position = $positions[$ride->id] ?? null;
            $reasons = [];

            if ($sosRideIds->has($ride->id)) {
                $reasons[] = 'sos';
            }

            // The driver's app has gone quiet mid-trip: the passenger cannot see them either.
            if ($ride->driver_id && in_array($ride->status, RideStatus::driverOnTripStatuses(), true)
                && ($position === null || $position['timestamp'] < $staleBefore)) {
                $reasons[] = 'driver_location_missing';
            }

            return ['ride' => $ride, 'position' => $position, 'reasons' => $reasons];
        });

        return response()->json([
            'city' => ['id' => $city->id, 'name' => $city->name],
            'cities' => $cityList,
            'summary' => [
                'active_rides' => $byCity->get($city->id, 0),
                'needs_attention' => $entries->filter(fn ($e) => $e['reasons'] !== [])->count(),
            ],
            'markers' => $entries->map(fn (array $e) => [
                'ride_id' => $e['ride']->id,
                'reference_number' => $e['ride']->reference_number,
                'status' => $e['ride']->status->value,
                'lat' => $e['position']['lat'] ?? (float) $e['ride']->pickup_lat,
                'lng' => $e['position']['lng'] ?? (float) $e['ride']->pickup_lng,
                'position_source' => $e['position'] ? 'driver' : 'pickup',
                'needs_attention' => $e['reasons'] !== [],
            ])->values(),
            'markers_truncated' => $truncated,
            'rides' => $entries->take($request->integer('rides_limit', 3))->map(fn (array $e) => [
                'id' => $e['ride']->id,
                'reference_number' => $e['ride']->reference_number,
                'status' => $e['ride']->status->value,
                'status_label' => $e['ride']->status->label(),
                'pickup_address' => $e['ride']->pickup_address,
                'destination_address' => $e['ride']->destination_address,
                // The account that booked the ride (as on every ride payload).
                'passenger' => $e['ride']->passenger ? [
                    'id' => $e['ride']->passenger->id,
                    'name' => $this->name($e['ride']->passenger),
                ] : null,
                'is_third_party' => (bool) $e['ride']->is_third_party,
                // The person being picked up: the booker, or the person they
                // booked for. Show this as the trip's passenger.
                'rider' => [
                    'user_id' => $e['ride']->is_third_party ? null : $e['ride']->passenger_id,
                    'name' => $e['ride']->is_third_party
                        ? (trim((string) $e['ride']->rider_name) ?: null)
                        : ($e['ride']->passenger ? $this->name($e['ride']->passenger) : null),
                ],
                'driver' => $e['ride']->driver ? [
                    'id' => $e['ride']->driver->id,
                    'name' => $this->name($e['ride']->driver),
                ] : null,
                'needs_attention' => $e['reasons'] !== [],
                'attention_reasons' => $e['reasons'],
                'requested_at' => $e['ride']->created_at?->toIso8601String(),
            ])->values(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{active_rides: array<string, mixed>, drivers_online: array<string, mixed>}
     */
    private function liveCards(): array
    {
        $now = $this->metrics->totals();
        $yesterday = OperationsSnapshot::totalsNear(now()->subDay());
        $comparedAt = $yesterday ? $yesterday['captured_at']->toIso8601String() : null;

        return [
            'active_rides' => [
                'count' => $now['active_rides'],
                'change_pct' => $yesterday ? $this->changePct($yesterday['active_rides'], $now['active_rides']) : null,
                'compared_at' => $comparedAt,
            ],
            'drivers_online' => [
                'count' => $now['drivers_online'],
                'on_trip' => $now['drivers_on_trip'],
                'utilisation_pct' => $now['drivers_online'] > 0
                    ? round($now['drivers_on_trip'] / $now['drivers_online'] * 100, 1)
                    : 0.0,
                'change_pct' => $yesterday ? $this->changePct($yesterday['drivers_online'], $now['drivers_online']) : null,
                'compared_at' => $comparedAt,
            ],
        ];
    }

    /**
     * Open cases the admin's queues cover; "overdue" have missed their next
     * response or resolution target.
     *
     * @return array{count: int, overdue: int}
     */
    private function openDisputes(User $admin): array
    {
        $open = fn () => $this->disputeAccess->scopeVisible(Dispute::query(), $admin)->open();

        return [
            'count' => $open()->count(),
            'overdue' => $open()->pastDue()->count(),
        ];
    }

    /**
     * Fares of rides completed today so far, against the same stretch of
     * yesterday. Gross fares, as on the revenue report.
     *
     * @return array<string, mixed>
     */
    private function revenueToday(): array
    {
        $now = now();
        $startOfToday = $now->copy()->timezone(self::BUSINESS_TIMEZONE)->startOfDay()->utc();

        $totals = fn (CarbonInterface $from, CarbonInterface $to) => Ride::where('status', RideStatus::Completed)
            ->where('completed_at', '>=', $from)
            ->where('completed_at', '<', $to)
            ->selectRaw('COALESCE(SUM(final_fare_amount), 0) AS amount, COUNT(*) AS rides')
            ->first();

        $today = $totals($startOfToday, $now);
        $yesterday = $totals($startOfToday->copy()->subDay(), $now->copy()->subDay());

        return [
            'period' => 'today',
            'timezone' => self::BUSINESS_TIMEZONE,
            'currency' => 'NGN',
            'amount' => round((float) $today->amount, 2),
            'completed_rides' => (int) $today->rides,
            'change_pct' => $this->changePct((float) $yesterday->amount, (float) $today->amount),
            'previous_amount' => round((float) $yesterday->amount, 2),
        ];
    }

    /**
     * Where each ride's driver is now, keyed by ride id; rides without a
     * driver or a live location are left out.
     *
     * @param  Collection<int, Ride>  $rides
     * @return array<string, array{lat: float, lng: float, heading: float, speed: float, timestamp: int}>
     */
    private function driverPositions(Collection $rides): array
    {
        $withDriver = $rides->filter(fn (Ride $ride) => $ride->driver_id
            && in_array($ride->status, RideStatus::driverOnTripStatuses(), true));

        // Locations are stored under the driver profile id, and under the
        // user id for older app builds.
        $byProfile = $this->locations->getDriverLocations(
            $withDriver->map(fn (Ride $ride) => (string) $ride->driver?->driver?->id)->filter()->values()->all(),
        );
        $missing = $withDriver->filter(fn (Ride $ride) => ! isset($byProfile[(string) $ride->driver?->driver?->id]));
        $byUser = $this->locations->getDriverLocations($missing->pluck('driver_id')->all());

        $positions = [];
        foreach ($withDriver as $ride) {
            $location = $byProfile[(string) $ride->driver?->driver?->id] ?? $byUser[$ride->driver_id] ?? null;

            if ($location !== null) {
                $positions[$ride->id] = $location;
            }
        }

        return $positions;
    }

    /**
     * Same rule as the reports: from zero, any rise is 100%.
     */
    private function changePct(float|int $previous, float|int $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function name(User $user): string
    {
        return trim($user->first_name.' '.$user->last_name);
    }
}
