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
    /**
     * @return array{otp: OtpCode, plain_code: string}
     */
    public function generateRidePin(string $rideId): array
    {
        OtpCode::where('purpose', 'ride_pin')
            ->where('phone', $rideId)
            ->active()
            ->update(['expires_at' => now()]);

        $plainCode = $this->generateCode(self::PIN_LENGTH);

        $otp = OtpCode::create([
            'phone' => $rideId,
            'code' => hash('sha256', $plainCode),
            'purpose' => 'ride_pin',
            'expires_at' => now()->addMinutes(self::PIN_EXPIRY_MINUTES),
        ]);

        return ['otp' => $otp, 'plain_code' => $plainCode];
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

        $updated = OtpCode::where('id', $otpCode->id)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($updated === 0) {
            return ['valid' => false, 'otp' => $otpCode->fresh(), 'error' => 'Maximum PIN verification attempts exceeded. Contact support.'];
        }

        if (! hash_equals($otpCode->code, hash('sha256', $code))) {
            return ['valid' => false, 'otp' => $otpCode->fresh(), 'error' => 'Invalid PIN code.'];
        }

        $otpCode->markAsVerified();

        return ['valid' => true, 'otp' => $otpCode->fresh(), 'error' => null];
    }

    private function generateCode(int $length): string
    {
        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
