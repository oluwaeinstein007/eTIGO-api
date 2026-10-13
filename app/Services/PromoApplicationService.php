<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Ride;
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
     * All context (city, vehicle class, pickup location, fare) is derived from the Ride model.
     *
     * @return array{success: bool, discount_amount: float|null, promo_code_id: string|null, reason: string|null}
     */
    public function applyToRide(string $code, Ride $ride): array
    {
        $fareAmount = (float) ($ride->final_fare_amount ?? $ride->fare_estimate_amount);

        return DB::transaction(function () use ($code, $ride, $fareAmount) {
            $promo = PromoCode::byCode($code)->lockForUpdate()->first();

            if (! $promo) {
                return ['success' => false, 'discount_amount' => null, 'promo_code_id' => null, 'reason' => 'Invalid promo code.'];
            }

            $validation = $this->validationService->validate(
                code: $code,
                userId: $ride->passenger_id,
                cityId: $ride->city_id,
                vehicleClassId: $ride->vehicle_class_id,
                pickupLat: $ride->pickup_lat ? (float) $ride->pickup_lat : null,
                pickupLng: $ride->pickup_lng ? (float) $ride->pickup_lng : null,
                fareAmount: $fareAmount,
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
                'user_id' => $ride->passenger_id,
                'ride_id' => $ride->id,
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
