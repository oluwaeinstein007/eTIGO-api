<?php

namespace App\Services;

use App\Models\Ride;
use Illuminate\Support\Facades\Cache;

class RidePinService
{
    public function __construct(
        private OtpService $otpService,
    ) {}

    /**
     * @return array{pin_code: string}
     */
    public function generatePin(Ride $ride): array
    {
        $result = $this->otpService->generateRidePin($ride->id);
        Cache::put("ride:{$ride->id}:pin_code", $result['plain_code'], now()->addHours(6));

        return ['pin_code' => $result['plain_code']];
    }

    /**
     * @return array{valid: bool, error: ?string}
     */
    public function verifyPin(Ride $ride, string $code): array
    {
        $result = $this->otpService->verifyRidePin($ride->id, $code);
        if ($result['valid']) {
            Cache::forget("ride:{$ride->id}:pin_code");
        }

        return [
            'valid' => $result['valid'],
            'error' => $result['error'],
        ];
    }

    public function getCachedPin(Ride $ride): ?string
    {
        return Cache::get("ride:{$ride->id}:pin_code");
    }
}
