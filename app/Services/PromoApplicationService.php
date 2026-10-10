<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use Illuminate\Support\Facades\DB;

class PromoApplicationService
{
    public function __construct(
        private PromoValidationService $validationService,
    ) {}

    /**
     * @return array{discount_amount: float, promo_code_id: string, code: string}
     */
    public function calculateDiscount(PromoCode $promo, float $fareAmount): array
    {
        $discount = match ($promo->discount_type) {
            DiscountType::Percentage => $fareAmount * ((float) $promo->discount_value / 100),
            DiscountType::Flat => (float) $promo->discount_value,
        };

        if ($promo->max_discount_cap !== null) {
            $discount = min($discount, (float) $promo->max_discount_cap);
        }

        $discount = min($discount, $fareAmount);
        $discount = round(max(0, $discount), 2);

        return [
            'discount_amount' => $discount,
            'promo_code_id' => $promo->id,
            'code' => $promo->code,
        ];
    }

    /**
     * Atomically validate and apply a promo code to a ride.
     *
     * @return array{success: bool, discount_amount: float|null, promo_code_id: string|null, reason: string|null}
     */
    public function apply(
        string $code,
        string $userId,
        string $rideId,
        float $fareAmount,
        ?string $cityId = null,
        ?string $vehicleClassId = null,
        ?float $pickupLat = null,
        ?float $pickupLng = null,
    ): array {
        return DB::transaction(function () use ($code, $userId, $rideId, $fareAmount, $cityId, $vehicleClassId, $pickupLat, $pickupLng) {
            $promo = PromoCode::byCode($code)->lockForUpdate()->first();

            if (! $promo) {
                return ['success' => false, 'discount_amount' => null, 'promo_code_id' => null, 'reason' => 'Invalid promo code.'];
            }

            $validation = $this->validationService->validate(
                $code, $userId, $cityId, $vehicleClassId, $pickupLat, $pickupLng, $fareAmount,
            );

            if (! $validation['valid']) {
                return ['success' => false, 'discount_amount' => null, 'promo_code_id' => null, 'reason' => $validation['reason']];
            }

            $calculation = $this->calculateDiscount($promo, $fareAmount);

            if ($calculation['discount_amount'] <= 0) {
                return ['success' => false, 'discount_amount' => null, 'promo_code_id' => null, 'reason' => 'No discount applicable for this fare.'];
            }

            PromoRedemption::create([
                'promo_code_id' => $promo->id,
                'user_id' => $userId,
                'ride_id' => $rideId,
                'discount_amount' => $calculation['discount_amount'],
                'redeemed_at' => now(),
            ]);

            return [
                'success' => true,
                'discount_amount' => $calculation['discount_amount'],
                'promo_code_id' => $promo->id,
                'reason' => null,
            ];
        });
    }
}
