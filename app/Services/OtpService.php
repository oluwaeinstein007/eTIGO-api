<?php

namespace App\Services;

use App\Models\OtpCode;

class OtpService
{
    private const PIN_LENGTH = 4;

    private const PIN_EXPIRY_MINUTES = 30;

    private const MAX_ATTEMPTS = 3;

    /**
     * Generate a ride-start PIN for trip verification.
     */
    public function generateRidePin(string $rideId): OtpCode
    {
        OtpCode::where('purpose', 'ride_pin')
            ->where('phone', $rideId)
            ->active()
            ->update(['expires_at' => now()]);

        $code = $this->generateCode(self::PIN_LENGTH);

        return OtpCode::create([
            'phone' => $rideId,
            'code' => $code,
            'purpose' => 'ride_pin',
            'expires_at' => now()->addMinutes(self::PIN_EXPIRY_MINUTES),
        ]);
    }

    /**
     * Verify a ride-start PIN submitted by the driver.
     *
     * @return array{valid: bool, otp: ?OtpCode, error: ?string}
     */
    public function verifyRidePin(string $rideId, string $code): array
    {
        $otpCode = OtpCode::where('phone', $rideId)
            ->where('purpose', 'ride_pin')
            ->active()
            ->latest()
            ->first();

        if (! $otpCode) {
            return ['valid' => false, 'otp' => null, 'error' => 'No active PIN found for this ride.'];
        }

        if ($otpCode->hasExceededMaxAttempts(self::MAX_ATTEMPTS)) {
            return ['valid' => false, 'otp' => $otpCode, 'error' => 'Maximum PIN verification attempts exceeded. Contact support.'];
        }

        if ($otpCode->code !== $code) {
            $otpCode->incrementAttempts();

            return ['valid' => false, 'otp' => $otpCode, 'error' => 'Invalid PIN code.'];
        }

        $otpCode->markAsVerified();

        return ['valid' => true, 'otp' => $otpCode, 'error' => null];
    }

    private function generateCode(int $length): string
    {
        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
