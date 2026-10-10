<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\StorePromoFormRequest;
use App\Http\Requests\Promo\UpdatePromoFormRequest;
use App\Http\Resources\PromoCodeResource;
use App\Http\Resources\PromoRedemptionResource;
use App\Models\AuditLog;
use App\Models\PromoCode;
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
                'expired' => $query->where('expires_at', '<', now()),
                'inactive' => $query->where('is_active', false),
                'scheduled' => $query->where('is_active', true)->where('starts_at', '>', now()),
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

    public function performance(PromoCode $promo): JsonResponse
    {
        $redemptions = $promo->redemptions()->with('user')->get();

        $totalDiscount = (float) $redemptions->sum('discount_amount');
        $redemptionCount = $redemptions->count();
        $uniqueUsers = $redemptions->pluck('user_id')->unique()->count();
        $avgDiscount = $redemptionCount > 0 ? round($totalDiscount / $redemptionCount, 2) : 0;

        $redemptionsByDay = $redemptions
            ->groupBy(fn ($r) => $r->redeemed_at->format('Y-m-d'))
            ->map(fn ($group) => [
                'count' => $group->count(),
                'total_discount' => round((float) $group->sum('discount_amount'), 2),
            ])
            ->sortKeys();

        $remainingRedemptions = $promo->total_redemption_limit
            ? max(0, $promo->total_redemption_limit - $redemptionCount)
            : null;

        return response()->json([
            'promo' => new PromoCodeResource($promo),
            'performance' => [
                'total_redemptions' => $redemptionCount,
                'unique_users' => $uniqueUsers,
                'total_discount_given' => $totalDiscount,
                'average_discount' => $avgDiscount,
                'remaining_redemptions' => $remainingRedemptions,
                'redemptions_by_day' => $redemptionsByDay,
            ],
            'recent_redemptions' => PromoRedemptionResource::collection(
                $promo->redemptions()->with(['user', 'promoCode'])->latest('redeemed_at')->limit(20)->get(),
            ),
        ]);
    }
}
