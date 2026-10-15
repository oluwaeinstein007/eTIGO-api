<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\StorePromoFormRequest;
use App\Http\Requests\Promo\UpdatePromoFormRequest;
use App\Http\Resources\PromoCodeResource;
use App\Http\Resources\PromoRedemptionResource;
use App\Models\AuditLog;
use App\Models\PromoCode;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPromoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PromoCode::with(['city', 'vehicleClass', 'createdByAdmin']);

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'active' => $query->active(),
                'expired' => $query->whereNull('cancelled_at')->where('expires_at', '<', now()),
                'inactive' => $query->whereNull('cancelled_at')->where('is_active', false),
                'scheduled' => $query->whereNull('cancelled_at')->where('is_active', true)->where('starts_at', '>', now()),
                'cancelled' => $query->whereNotNull('cancelled_at'),
                default => null,
            };
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', $request->input('city_id'));
        }

        if ($request->filled('search')) {
            $search = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        $promos = $query->withCount('redemptions')
            ->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

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
        $validated = $request->validated();
        $admin = $request->user();

        $promo = PromoCode::create([
            ...$validated,
            'created_by_admin_id' => $admin->id,
        ]);

        AuditLog::record($promo, 'promo_code_created', $admin, null, [
            'code' => $promo->code,
            'discount_type' => $promo->discount_type->value,
            'discount_value' => $promo->discount_value,
        ]);

        return response()->json([
            'message' => 'Promo code created successfully.',
            'promo' => new PromoCodeResource($promo->load(['city', 'vehicleClass'])),
        ], 201);
    }

    public function show(PromoCode $promo): JsonResponse
    {
        return response()->json([
            'promo' => new PromoCodeResource(
                $promo->load(['city', 'vehicleClass', 'createdByAdmin', 'redemptions']),
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

        $validated = $request->validated();
        $admin = $request->user();

        $oldValues = $promo->only([
            'code', 'description', 'discount_type', 'discount_value', 'max_discount_cap',
            'total_redemption_limit', 'per_user_limit', 'starts_at', 'expires_at',
            'is_active', 'city_id', 'vehicle_class_id',
        ]);

        $promo->update($validated);

        AuditLog::record($promo, 'promo_code_updated', $admin, $oldValues, $promo->only(array_keys($oldValues)));

        return response()->json([
            'message' => 'Promo code updated successfully.',
            'promo' => new PromoCodeResource($promo->load(['city', 'vehicleClass'])),
        ]);
    }

    public function toggleStatus(Request $request, PromoCode $promo): JsonResponse
    {
        if ($promo->isCancelled()) {
            return response()->json([
                'message' => 'Cannot change status of a cancelled promo code.',
            ], 422);
        }

        $admin = $request->user();

        $promo->update(['is_active' => ! $promo->is_active]);

        AuditLog::record($promo, 'promo_code_toggled', $admin, null, [
            'is_active' => $promo->is_active,
        ]);

        return response()->json([
            'message' => $promo->is_active ? 'Promo code activated.' : 'Promo code deactivated.',
            'promo' => new PromoCodeResource($promo),
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
            'promo' => new PromoCodeResource($promo),
        ]);
    }

    public function performance(Request $request, PromoCode $promo): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $redemptionQuery = $promo->redemptions()->with('user');

        if ($request->filled('start_date')) {
            $redemptionQuery->whereDate('redeemed_at', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $redemptionQuery->whereDate('redeemed_at', '<=', $request->input('end_date'));
        }

        $redemptions = $redemptionQuery->get();

        $totalDiscount = (float) $redemptions->sum('discount_amount');
        $redemptionCount = $redemptions->count();
        $uniqueUsers = $redemptions->pluck('user_id')->unique()->count();
        $avgDiscount = $redemptionCount > 0 ? round($totalDiscount / $redemptionCount, 2) : 0;

        $rideIds = $redemptions->pluck('ride_id')->filter();
        $totalFareValue = 0;
        if ($rideIds->isNotEmpty()) {
            $fareSum = Ride::whereIn('id', $rideIds)
                ->selectRaw('COALESCE(SUM(COALESCE(final_fare_amount, 0) + COALESCE(discount_amount, 0)), 0) as total')
                ->value('total');
            $totalFareValue = (float) $fareSum;
        }

        $redemptionsByDay = $redemptions
            ->groupBy(fn ($r) => $r->redeemed_at->format('Y-m-d'))
            ->map(fn ($group) => [
                'count' => $group->count(),
                'total_discount' => round((float) $group->sum('discount_amount'), 2),
            ])
            ->sortKeys();

        $allRedemptionCount = $promo->redemptions()->count();
        $remainingRedemptions = $promo->total_redemption_limit
            ? max(0, $promo->total_redemption_limit - $allRedemptionCount)
            : null;

        $usagePercentage = $promo->total_redemption_limit
            ? round(($allRedemptionCount / $promo->total_redemption_limit) * 100, 1)
            : null;

        return response()->json([
            'promo' => new PromoCodeResource($promo),
            'performance' => [
                'total_redemptions' => $redemptionCount,
                'unique_users' => $uniqueUsers,
                'total_discount_given' => $totalDiscount,
                'total_fare_value' => round($totalFareValue, 2),
                'average_discount' => $avgDiscount,
                'remaining_redemptions' => $remainingRedemptions,
                'usage_percentage' => $usagePercentage,
                'redemptions_by_day' => $redemptionsByDay,
            ],
            'recent_redemptions' => PromoRedemptionResource::collection(
                $promo->redemptions()->with(['user', 'promoCode'])->latest('redeemed_at')->limit(20)->get(),
            ),
        ]);
    }
}
