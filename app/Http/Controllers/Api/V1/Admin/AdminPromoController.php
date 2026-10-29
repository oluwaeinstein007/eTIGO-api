<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PromoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\StorePromoFormRequest;
use App\Http\Requests\Promo\UpdatePromoFormRequest;
use App\Http\Resources\PromoCodeResource;
use App\Http\Resources\PromoRedemptionResource;
use App\Models\AuditLog;
use App\Models\PromoCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPromoController extends Controller
{
    /**
     * The longest range the performance chart covers, so a promo running for
     * years cannot ask for thousands of daily bars at once.
     */
    private const MAX_PERFORMANCE_DAYS = 366;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            // "inactive" is the name the filter had before "paused".
            'status' => ['nullable', Rule::in([...array_column(PromoStatus::cases(), 'value'), 'inactive'])],
            'city_id' => ['nullable', 'uuid'],
            // With city_id: false leaves out promos that run in every city.
            'include_all_cities' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = PromoCode::with(['cities', 'vehicleClass', 'createdByAdmin']);

        if ($request->filled('status')) {
            $status = $request->input('status') === 'inactive'
                ? PromoStatus::Paused
                : PromoStatus::from($request->input('status'));

            $query->withStatus($status);
        }

        // What runs in the city: promos limited to it, and promos with no
        // cities (they run everywhere, that city included).
        if ($request->filled('city_id')) {
            $request->boolean('include_all_cities', true)
                ? $query->availableInCity($request->input('city_id'))
                : $query->whereHas('cities', fn ($q) => $q->whereKey($request->input('city_id')));
        }

        if ($request->filled('search')) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        $promos = $query->withCount('redemptions')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'promos' => PromoCodeResource::collection($promos),
            'meta' => [
                'current_page' => $promos->currentPage(),
                'last_page' => $promos->lastPage(),
                'per_page' => $promos->perPage(),
                'total' => $promos->total(),
            ],
        ]);
    }

    public function store(StorePromoFormRequest $request): JsonResponse
    {
        $admin = $request->user();

        $promo = DB::transaction(function () use ($request, $admin) {
            $promo = PromoCode::create([
                ...$request->promoAttributes(),
                'created_by_admin_id' => $admin->id,
            ]);

            $promo->cities()->sync($request->cityIds() ?? []);

            AuditLog::record($promo, 'promo_code_created', $admin, null, [
                'code' => $promo->code,
                'discount_type' => $promo->discount_type->value,
                'discount_value' => $promo->discount_value,
                'city_ids' => $request->cityIds() ?? [],
            ]);

            return $promo;
        });

        return response()->json([
            'message' => 'Promo code created successfully.',
            'promo' => new PromoCodeResource($promo->load(['cities', 'vehicleClass'])->loadCount('redemptions')),
        ], 201);
    }

    public function show(PromoCode $promo): JsonResponse
    {
        return response()->json([
            'promo' => new PromoCodeResource(
                $promo->load(['cities', 'vehicleClass', 'createdByAdmin'])->loadCount('redemptions'),
            ),
        ]);
    }

    public function update(UpdatePromoFormRequest $request, PromoCode $promo): JsonResponse
    {
        if ($promo->isCancelled()) {
            return response()->json([
                'message' => 'Cannot update a cancelled promo code.',
            ], 422);
        }

        $admin = $request->user();

        $oldValues = [
            ...$promo->only([
                'code', 'description', 'discount_type', 'discount_value', 'max_discount_cap',
                'total_redemption_limit', 'per_user_limit', 'starts_at', 'expires_at',
                'min_order_count', 'max_order_count', 'peak_only', 'off_peak_only',
                'is_active', 'vehicle_class_id',
            ]),
            'city_ids' => $promo->cities()->pluck('cities.id')->all(),
        ];

        DB::transaction(function () use ($request, $promo, $admin, $oldValues) {
            $promo->update($request->promoAttributes());

            if ($request->cityIds() !== null) {
                $promo->cities()->sync($request->cityIds());
            }

            AuditLog::record($promo, 'promo_code_updated', $admin, $oldValues, [
                ...$promo->only(array_keys($oldValues)),
                'city_ids' => $promo->cities()->pluck('cities.id')->all(),
            ]);
        });

        return response()->json([
            'message' => 'Promo code updated successfully.',
            'promo' => new PromoCodeResource($promo->load(['cities', 'vehicleClass'])->loadCount('redemptions')),
        ]);
    }

    /**
     * @deprecated Use pause and resume; this flips is_active either way.
     */
    public function toggleStatus(Request $request, PromoCode $promo): JsonResponse
    {
        $response = $promo->is_active
            ? $this->pause($request, $promo)
            : $this->resume($request, $promo);

        if ($response->isSuccessful()) {
            $response->setData([
                ...$response->getData(true),
                'message' => $promo->is_active ? 'Promo code activated.' : 'Promo code deactivated.',
            ]);
        }

        return $response;
    }

    public function pause(Request $request, PromoCode $promo): JsonResponse
    {
        if ($promo->isCancelled()) {
            return response()->json(['message' => 'Cannot change status of a cancelled promo code.'], 422);
        }

        if ($promo->is_active) {
            $promo->update(['is_active' => false]);

            AuditLog::record($promo, 'promo_code_paused', $request->user(), ['is_active' => true], ['is_active' => false]);
        }

        return response()->json([
            'message' => 'Promo code paused.',
            'promo' => new PromoCodeResource($promo->load(['cities', 'vehicleClass'])->loadCount('redemptions')),
        ]);
    }

    public function resume(Request $request, PromoCode $promo): JsonResponse
    {
        if ($promo->isCancelled()) {
            return response()->json(['message' => 'Cannot change status of a cancelled promo code.'], 422);
        }

        if ($promo->status() === PromoStatus::Expired) {
            return response()->json([
                'message' => 'This promo code has expired. Move its end date later to run it again.',
            ], 422);
        }

        if (! $promo->is_active) {
            $promo->update(['is_active' => true]);

            AuditLog::record($promo, 'promo_code_resumed', $request->user(), ['is_active' => false], ['is_active' => true]);
        }

        return response()->json([
            'message' => 'Promo code resumed.',
            'promo' => new PromoCodeResource($promo->load(['cities', 'vehicleClass'])->loadCount('redemptions')),
        ]);
    }

    public function cancel(Request $request, PromoCode $promo): JsonResponse
    {
        if ($promo->isCancelled()) {
            return response()->json([
                'message' => 'This promo code has already been cancelled.',
            ], 422);
        }

        $admin = $request->user();

        $promo->update([
            'is_active' => false,
            'cancelled_at' => now(),
        ]);

        AuditLog::record($promo, 'promo_code_cancelled', $admin, null, [
            'code' => $promo->code,
        ]);

        return response()->json([
            'message' => 'Promo code cancelled permanently.',
            'promo' => new PromoCodeResource($promo->load(['cities', 'vehicleClass'])->loadCount('redemptions')),
        ]);
    }

    /**
     * Figures for a date range (in the promo timezone, Africa/Lagos by default),
     * with one entry per day, and usage against the limit over the promo's life.
     */
    public function performance(Request $request, PromoCode $promo): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $timezone = config('promo.timezone', 'Africa/Lagos');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $start = $request->filled('start_date')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $request->input('start_date'), $timezone)
            : $promo->starts_at->toImmutable()->setTimezone($timezone)->startOfDay();
        $end = $request->filled('end_date')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $request->input('end_date'), $timezone)
            : $promo->expires_at->toImmutable()->setTimezone($timezone)->startOfDay()->min($today)->max($start);

        if ((int) $start->diffInDays($end) + 1 > self::MAX_PERFORMANCE_DAYS) {
            if ($request->filled('start_date') && $request->filled('end_date')) {
                return response()->json([
                    'message' => 'The date range can cover at most '.self::MAX_PERFORMANCE_DAYS.' days.',
                    'errors' => ['end_date' => ['The date range can cover at most '.self::MAX_PERFORMANCE_DAYS.' days.']],
                ], 422);
            }

            // Default range on a long-running promo: its latest year.
            $start = $end->subDays(self::MAX_PERFORMANCE_DAYS - 1);
        }

        $from = $start->utc();
        $until = $end->addDay()->utc();

        $inRange = fn () => $promo->redemptions()
            ->where('promo_redemptions.redeemed_at', '>=', $from)
            ->where('promo_redemptions.redeemed_at', '<', $until);

        $totals = $inRange()
            ->selectRaw('COUNT(*) AS redemptions, COUNT(DISTINCT promo_redemptions.user_id) AS unique_users, COALESCE(SUM(promo_redemptions.discount_amount), 0) AS discount')
            ->toBase()
            ->first();

        $totalFareValue = (float) $inRange()
            ->join('rides', 'rides.id', '=', 'promo_redemptions.ride_id')
            ->selectRaw('COALESCE(SUM(COALESCE(rides.final_fare_amount, 0) + COALESCE(rides.discount_amount, 0)), 0) AS total')
            ->toBase()
            ->value('total');

        $localDay = "((promo_redemptions.redeemed_at AT TIME ZONE 'UTC') AT TIME ZONE ?)::date";
        $byDay = $inRange()
            ->selectRaw("{$localDay} AS day, COUNT(*) AS count, COALESCE(SUM(promo_redemptions.discount_amount), 0) AS total_discount", [$timezone])
            // By position: the bound timezone would make a repeated expression differ.
            ->groupByRaw('1')
            ->toBase()
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $redemptionsByDay = collect(CarbonPeriod::create($start, $end))
            ->map(function ($day) use ($byDay) {
                $row = $byDay->get($day->format('Y-m-d'));

                return [
                    'date' => $day->format('Y-m-d'),
                    'count' => (int) ($row->count ?? 0),
                    'total_discount' => round((float) ($row->total_discount ?? 0), 2),
                ];
            })
            ->values();

        $redemptionCount = (int) $totals->redemptions;
        $totalDiscount = round((float) $totals->discount, 2);

        $lifetimeRedemptions = $promo->redemptions()->count();
        $limit = $promo->total_redemption_limit;

        return response()->json([
            'promo' => new PromoCodeResource($promo->load(['cities', 'vehicleClass'])->loadCount('redemptions')),
            'performance' => [
                'period' => [
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $end->format('Y-m-d'),
                    'timezone' => $timezone,
                ],
                'total_redemptions' => $redemptionCount,
                'unique_users' => (int) $totals->unique_users,
                'total_discount_given' => $totalDiscount,
                'total_fare_value' => round($totalFareValue, 2),
                'average_discount' => $redemptionCount > 0 ? round($totalDiscount / $redemptionCount, 2) : 0,
                // Usage is measured against the promo's total limit, so these
                // cover its whole life whatever the date range.
                'lifetime_redemptions' => $lifetimeRedemptions,
                'total_redemption_limit' => $limit,
                'remaining_redemptions' => $limit ? max(0, $limit - $lifetimeRedemptions) : null,
                'usage_percentage' => $limit ? round(($lifetimeRedemptions / $limit) * 100, 1) : null,
                'redemptions_by_day' => $redemptionsByDay,
            ],
            'recent_redemptions' => PromoRedemptionResource::collection(
                $promo->redemptions()->with(['user', 'promoCode'])->latest('redeemed_at')->limit(20)->get(),
            ),
        ]);
    }
}
