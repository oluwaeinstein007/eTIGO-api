<?php

namespace App\Services;

use App\Enums\RideStatus;
use App\Models\City;
use App\Models\PromoCode;
use App\Models\Ride;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PromoValidationService
{
    public function __construct(
        private TierGateService $tierGateService,
    ) {}

    /**
     * @return array{valid: bool, promo: PromoCode|null, reason: string|null}
     */
    /**
     * @param  string|null  $excludeRideId  A ride already completed by this user that must not
     *                                      count towards order-count rules (the ride being settled)
     * @param  CarbonInterface|null  $evaluatedAt  Evaluate the time window and peak rules at this
     *                                             instant (the booking time when settling a ride)
     */
    public function validate(
        string $code,
        string $userId,
        ?string $cityId = null,
        ?string $vehicleClassId = null,
        ?float $pickupLat = null,
        ?float $pickupLng = null,
        ?float $fareAmount = null,
        ?string $excludeRideId = null,
        ?CarbonInterface $evaluatedAt = null,
    ): array {
        $promo = PromoCode::byCode($code)->first();
        $evaluatedAt ??= now();

        if (! $promo) {
            return $this->reject('Invalid promo code.');
        }

        if (! $promo->is_active) {
            return $this->reject('This promo code is no longer active.');
        }

        if (! $promo->isWithinTimeWindow($evaluatedAt)) {
            if ($evaluatedAt->lt($promo->starts_at)) {
                return $this->reject('This promo code is not yet active.');
            }

            return $this->reject('This promo code has expired.');
        }

        if (! $promo->isAvailableInCity($cityId)) {
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

        if (! $this->checkPeakConstraint($promo, $evaluatedAt, $cityId)) {
            if ($promo->peak_only) {
                return $this->reject('This promo code is only valid during peak hours.');
            }

            return $this->reject('This promo code is only valid during off-peak hours.');
        }

        if (! $this->tierGateService->qualifiesForTierPromo($userId, $promo->min_tier_level)) {
            return $this->reject('Your tier level does not qualify for this promo code.');
        }

        $userCompletedRides = $this->getUserCompletedRideCount($userId, $excludeRideId);

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
     * Validate a promo code for a user without ride context.
     * Checks: code existence, active status, time window, user eligibility.
     * Defers ride-context checks (city, vehicle class, fare, geo-fence, peak/off-peak) to apply time.
     *
     * @return array{valid: bool, promo: PromoCode|null, reason: string|null}
     */
    public function validateForUser(string $code, string $userId): array
    {
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

        if (! $this->tierGateService->qualifiesForTierPromo($userId, $promo->min_tier_level)) {
            return $this->reject('Your tier level does not qualify for this promo code.');
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

        $promo->load(['cities', 'vehicleClass']);

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

        if (! $this->tierGateService->qualifiesForTierPromo($userId, $promo->min_tier_level)) {
            return false;
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

    private function checkPeakConstraint(PromoCode $promo, ?CarbonInterface $at = null, ?string $cityId = null): bool
    {
        if (! $promo->peak_only && ! $promo->off_peak_only) {
            return true;
        }

        $timezone = ($cityId ? City::whereKey($cityId)->value('timezone') : null)
            ?? config('promo.timezone', 'Africa/Lagos');

        $isPeak = $this->isPeakHour(($at ?? now())->copy()->setTimezone($timezone));

        if ($promo->peak_only) {
            return $isPeak;
        }

        return ! $isPeak;
    }

    private function isPeakHour(CarbonInterface $localTime): bool
    {
        $hour = $localTime->format('H:i');

        $peakMorningStart = config('promo.peak_hours.morning_start', '07:00');
        $peakMorningEnd = config('promo.peak_hours.morning_end', '09:00');
        $peakEveningStart = config('promo.peak_hours.evening_start', '17:00');
        $peakEveningEnd = config('promo.peak_hours.evening_end', '19:00');

        return ($hour >= $peakMorningStart && $hour < $peakMorningEnd)
            || ($hour >= $peakEveningStart && $hour < $peakEveningEnd);
    }

    private function getUserCompletedRideCount(string $userId, ?string $excludeRideId = null): int
    {
        return Ride::where('passenger_id', $userId)
            ->where('status', RideStatus::Completed)
            ->when($excludeRideId, fn ($query) => $query->where('id', '!=', $excludeRideId))
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
