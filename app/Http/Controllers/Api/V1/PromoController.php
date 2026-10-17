<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\ValidatePromoFormRequest;
use App\Http\Resources\PromoCodeResource;
use App\Http\Resources\PromoRedemptionResource;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Services\PromoValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    public function __construct(
        private PromoValidationService $validationService,
    ) {}

    public function validate(ValidatePromoFormRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $result = $this->validationService->validateForUser(
            code: $validated['code'],
            userId: $user->id,
        );

        if (! $result['valid']) {
            return response()->json([
                'valid' => false,
                'message' => $result['reason'],
            ], 422);
        }

        return response()->json([
            'valid' => true,
            'promo' => new PromoCodeResource($result['promo']),
        ]);
    }

    public function available(Request $request): JsonResponse
    {
        $user = $request->user();
        $cityId = $request->query('city_id');

        $promos = PromoCode::active()
            ->availableInCity($cityId)
            ->with(['cities', 'vehicleClass'])
            ->orderBy('expires_at')
            ->get();

        $eligible = $promos->filter(function (PromoCode $promo) use ($user) {
            return $this->validationService->isUserEligible($promo, $user->id);
        })->values();

        return response()->json([
            'promos' => PromoCodeResource::collection($eligible),
        ]);
    }

    public function redemptions(Request $request): JsonResponse
    {
        $user = $request->user();

        $redemptions = PromoRedemption::where('user_id', $user->id)
            ->with(['promoCode', 'ride'])
            ->orderByDesc('redeemed_at')
            ->paginate(min(max($request->integer('per_page', 15), 1), 50));

        return response()->json([
            'redemptions' => PromoRedemptionResource::collection($redemptions),
            'meta' => [
                'current_page' => $redemptions->currentPage(),
                'last_page' => $redemptions->lastPage(),
                'per_page' => $redemptions->perPage(),
                'total' => $redemptions->total(),
            ],
        ]);
    }
}
