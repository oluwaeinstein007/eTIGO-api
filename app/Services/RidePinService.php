<?php

namespace App\Services;

use App\Models\Ride;

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

        return ['pin_code' => $result['plain_code']];
    }

    /**
     * @return array{valid: bool, error: ?string}
     */
    public function verifyPin(Ride $ride, string $code): array
    {
        $result = $this->otpService->verifyRidePin($ride->id, $code);

        return [
            'valid' => $result['valid'],
            'error' => $result['error'],
        ];
    }
}
