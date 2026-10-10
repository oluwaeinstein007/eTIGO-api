<?php

namespace App\Services;

use App\Enums\RideStatus;
use App\Models\PromoCode;
use App\Models\Ride;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PromoValidationService
{
    public function __construct(
        private TierGateService $tierGateService,
    ) {}

    /**
     * @return array{valid: bool, promo: PromoCode|null, reason: string|null}
     */
    public function validate(
        string $code,
        string $userId,
        ?string $cityId = null,
        ?string $vehicleClassId = null,
        ?float $pickupLat = null,
        ?float $pickupLng = null,
        ?float $fareAmount = null,
    ): array {
        $promo = PromoCode::byCode($code)->first();

        if (! $promo) {
            return $this->reject('Invalid promo code.');
        }

        if (! $promo->is_active) {
            return $this->reject('This promo code is no longer active.');
        }

        if (! $promo->isWithinTimeWindow()) {
            if (now()->lt($promo->starts_at)) {
                return $this->reject('This promo code is not yet active.');
            }

            return $this->reject('This promo code has expired.');
        }

        if ($promo->city_id && ($cityId === null || $promo->city_id !== $cityId)) {
            return $this->reject('This promo code is not valid in your city.');
        }

        if ($promo->vehicle_class_id && ($vehicleClassId === null || $promo->vehicle_class_id !== $vehicleClassId)) {
            return $this->reject('This promo code is not valid for the selected vehicle class.');
        }

        if ($promo->minimum_fare_amount && ($fareAmount === null || $fareAmount < (float) $promo->minimum_fare_amount)) {
            return $this->reject("Minimum fare of {$promo->minimum_fare_amount} required to use this code.");
        }

        if ($promo->geo_fence && (
            $pickupLat === null
            || $pickupLng === null
            || ! $this->isWithinGeoFence($pickupLat, $pickupLng, $promo->geo_fence)
        )) {
            return $this->reject('This promo code is not valid in your area.');
        }

        if (! $this->checkPeakConstraint($promo)) {
            if ($promo->peak_only) {
                return $this->reject('This promo code is only valid during peak hours.');
            }

            return $this->reject('This promo code is only valid during off-peak hours.');
        }

        if ($promo->min_tier_level) {
            $userTier = $this->tierGateService->getUserTierLevel($userId);
            if ($userTier < $promo->min_tier_level) {
                return $this->reject('Your tier level does not qualify for this promo code.');
            }
        }

        $userCompletedRides = $this->getUserCompletedRideCount($userId);

        if ($promo->min_order_count !== null && $userCompletedRides < $promo->min_order_count) {
            return $this->reject("You need at least {$promo->min_order_count} completed rides to use this code.");
        }

        if ($promo->max_order_count !== null && $userCompletedRides > $promo->max_order_count) {
            return $this->reject('This promo code is for new users only.');
        }

        if ($promo->hasReachedUserLimit($userId)) {
            return $this->reject('You have already used this promo code the maximum number of times.');
        }

        if ($promo->hasReachedGlobalLimit()) {
            return $this->reject('This promo code has reached its redemption limit.');
        }

        return ['valid' => true, 'promo' => $promo, 'reason' => null];
    }

    /**
     * Check user-level eligibility only (tier, order count, redemption limits).
     * Skips ride-context checks (city, vehicle class, fare, geo-fence, peak/off-peak).
     * Used by the available promos listing where ride context is unknown.
     */
    public function isUserEligible(PromoCode $promo, string $userId): bool
    {
        if (! $promo->is_active || ! $promo->isWithinTimeWindow()) {
            return false;
        }

        if ($promo->min_tier_level) {
            $userTier = $this->tierGateService->getUserTierLevel($userId);
            if ($userTier < $promo->min_tier_level) {
                return false;
            }
        }

        $userCompletedRides = $this->getUserCompletedRideCount($userId);

        if ($promo->min_order_count !== null && $userCompletedRides < $promo->min_order_count) {
            return false;
        }

        if ($promo->max_order_count !== null && $userCompletedRides > $promo->max_order_count) {
            return false;
        }

        if ($promo->hasReachedUserLimit($userId)) {
            return false;
        }

        if ($promo->hasReachedGlobalLimit()) {
            return false;
        }

        return true;
    }

    /**
     * Atomically validate and lock for redemption to prevent race conditions.
     *
     * @return array{valid: bool, promo: PromoCode|null, reason: string|null}
     */
    public function validateAndLock(
        string $code,
        string $userId,
        ?string $cityId = null,
        ?string $vehicleClassId = null,
        ?float $pickupLat = null,
        ?float $pickupLng = null,
        ?float $fareAmount = null,
    ): array {
        return DB::transaction(function () use ($code, $userId, $cityId, $vehicleClassId, $pickupLat, $pickupLng, $fareAmount) {
            $promo = PromoCode::byCode($code)->lockForUpdate()->first();

            if (! $promo) {
                return $this->reject('Invalid promo code.');
            }

            return $this->validate($code, $userId, $cityId, $vehicleClassId, $pickupLat, $pickupLng, $fareAmount);
        });
    }

    private function isWithinGeoFence(float $lat, float $lng, array $geoFence): bool
    {
        if (isset($geoFence['center_lat'], $geoFence['center_lng'], $geoFence['radius_km'])) {
            $distance = $this->haversineDistance(
                $lat, $lng,
                $geoFence['center_lat'], $geoFence['center_lng'],
            );

            return $distance <= $geoFence['radius_km'];
        }

        if (isset($geoFence['polygon']) && is_array($geoFence['polygon'])) {
            return $this->isPointInPolygon($lat, $lng, $geoFence['polygon']);
        }

        return true;
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function isPointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $n = count($polygon);
        if ($n < 3) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i]['lat'] ?? $polygon[$i][0];
            $yi = $polygon[$i]['lng'] ?? $polygon[$i][1];
            $xj = $polygon[$j]['lat'] ?? $polygon[$j][0];
            $yj = $polygon[$j]['lng'] ?? $polygon[$j][1];

            $intersect = (($yi > $lng) !== ($yj > $lng))
                && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function checkPeakConstraint(PromoCode $promo): bool
    {
        if (! $promo->peak_only && ! $promo->off_peak_only) {
            return true;
        }

        $isPeak = $this->isPeakHour();

        if ($promo->peak_only) {
            return $isPeak;
        }

        return ! $isPeak;
    }

    private function isPeakHour(): bool
    {
        $now = Carbon::now();
        $hour = $now->format('H:i');

        $peakMorningStart = config('promo.peak_hours.morning_start', '07:00');
        $peakMorningEnd = config('promo.peak_hours.morning_end', '09:00');
        $peakEveningStart = config('promo.peak_hours.evening_start', '17:00');
        $peakEveningEnd = config('promo.peak_hours.evening_end', '19:00');

        return ($hour >= $peakMorningStart && $hour < $peakMorningEnd)
            || ($hour >= $peakEveningStart && $hour < $peakEveningEnd);
    }

    private function getUserCompletedRideCount(string $userId): int
    {
        return Ride::where('passenger_id', $userId)
            ->where('status', RideStatus::Completed)
            ->count();
    }

    /**
     * @return array{valid: false, promo: null, reason: string}
     */
    private function reject(string $reason): array
    {
        return ['valid' => false, 'promo' => null, 'reason' => $reason];
    }
}
