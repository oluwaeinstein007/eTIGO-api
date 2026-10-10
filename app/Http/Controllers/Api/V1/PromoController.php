<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\ValidatePromoFormRequest;
use App\Http\Resources\PromoCodeResource;
use App\Models\PromoCode;
use App\Services\PromoApplicationService;
use App\Services\PromoValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    public function __construct(
        private PromoValidationService $validationService,
        private PromoApplicationService $applicationService,
    ) {}

    public function validate(ValidatePromoFormRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $result = $this->validationService->validate(
            code: $validated['code'],
            userId: $user->id,
            cityId: $validated['city_id'] ?? null,
            vehicleClassId: $validated['vehicle_class_id'] ?? null,
            pickupLat: $validated['pickup_lat'] ?? null,
            pickupLng: $validated['pickup_lng'] ?? null,
            fareAmount: $validated['fare_amount'] ?? null,
        );

        if (! $result['valid']) {
            return response()->json([
                'valid' => false,
                'message' => $result['reason'],
            ], 422);
        }

        $promo = $result['promo'];
        $discountPreview = null;

        if (isset($validated['fare_amount']) && $validated['fare_amount'] > 0) {
            $discountPreview = $this->applicationService->calculateDiscount($promo, $validated['fare_amount']);
        }

        return response()->json([
            'valid' => true,
            'promo' => new PromoCodeResource($promo),
            'discount_preview' => $discountPreview ? [
                'discount_amount' => $discountPreview['discount_amount'],
                'discount_type' => $promo->discount_type->value,
                'discount_value' => $promo->discount_value,
                'max_discount_cap' => $promo->max_discount_cap,
            ] : null,
        ]);
    }

    public function available(Request $request): JsonResponse
    {
        $user = $request->user();

        $promos = PromoCode::active()
            ->where(function ($query) use ($user) {
                $query->whereNull('city_id');

                if ($user->isDriver() && $user->driver?->city_id) {
                    $query->orWhere('city_id', $user->driver->city_id);
                }
            })
            ->orderBy('expires_at')
            ->get();

        $eligible = $promos->filter(function (PromoCode $promo) use ($user) {
            return $this->validationService->isUserEligible($promo, $user->id);
        })->values();

        return response()->json([
            'promos' => PromoCodeResource::collection($eligible),
        ]);
    }
}
