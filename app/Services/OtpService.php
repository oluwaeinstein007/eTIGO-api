<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use App\Models\OtpCode;

class OtpService
{
    private const AUTH_OTP_LENGTH = 6;

    private const AUTH_OTP_EXPIRY_MINUTES = 5;

    private const AUTH_OTP_COOLDOWN_SECONDS = 60;

    private const AUTH_MAX_ATTEMPTS = 5;

    private const STATIC_OTP = '123456';

    private const PIN_LENGTH = 4;

    private const PIN_EXPIRY_MINUTES = 30;

    private const RIDE_MAX_ATTEMPTS = 3;

    public function __construct(
        private SmsGateway $smsGateway,
    ) {}

    /**
     * @return array{sent: bool, error: ?string, expires_at: ?string}
     */
    public function sendAuthOtp(string $phone): array
    {
        $recentOtp = OtpCode::forPhone($phone)
            ->where('purpose', 'login')
            ->where('created_at', '>', now()->subSeconds(self::AUTH_OTP_COOLDOWN_SECONDS))
            ->first();

        if ($recentOtp) {
            return [
                'sent' => false,
                'error' => 'Please wait before requesting another code.',
                'expires_at' => null,
            ];
        }

        OtpCode::forPhone($phone)
            ->where('purpose', 'login')
            ->active()
            ->update(['expires_at' => now()]);

        $useStaticOtp = ! app()->isProduction();
        $plainCode = $useStaticOtp ? self::STATIC_OTP : $this->generateCode(self::AUTH_OTP_LENGTH);

        $otp = OtpCode::create([
            'phone' => $phone,
            'code' => hash('sha256', $plainCode),
            'purpose' => 'login',
            'expires_at' => now()->addMinutes(self::AUTH_OTP_EXPIRY_MINUTES),
        ]);

        if (! $useStaticOtp) {
            $sent = $this->smsGateway->send($phone, $plainCode);

            if (! $sent) {
                $otp->update(['expires_at' => now()]);

                return [
                    'sent' => false,
                    'error' => 'Failed to send verification code. Please try again.',
                    'expires_at' => null,
                ];
            }
        }

        return [
            'sent' => true,
            'error' => null,
            'expires_at' => $otp->expires_at->toIso8601String(),
        ];
    }

    /**
     * @return array{valid: bool, phone: ?string, error: ?string}
     */
    public function verifyAuthOtp(string $phone, string $code): array
    {
        $otpCode = OtpCode::forPhone($phone)
            ->where('purpose', 'login')
            ->active()
            ->latest()
            ->first();

        if (! $otpCode) {
            return ['valid' => false, 'phone' => null, 'error' => 'No active verification code found. Please request a new one.'];
        }

        if ($otpCode->hasExceededMaxAttempts(self::AUTH_MAX_ATTEMPTS)) {
            $otpCode->update(['expires_at' => now()]);

            return ['valid' => false, 'phone' => null, 'error' => 'Too many attempts. Please request a new code.'];
        }

        $updated = OtpCode::where('id', $otpCode->id)
            ->where('attempts', '<', self::AUTH_MAX_ATTEMPTS)
            ->increment('attempts');

        if ($updated === 0) {
            return ['valid' => false, 'phone' => null, 'error' => 'Too many attempts. Please request a new code.'];
        }

        if (! hash_equals($otpCode->code, hash('sha256', $code))) {
            return ['valid' => false, 'phone' => null, 'error' => 'Invalid verification code.'];
        }

        $otpCode->markAsVerified();

        return ['valid' => true, 'phone' => $phone, 'error' => null];
    }

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
            ->where('attempts', '<', self::RIDE_MAX_ATTEMPTS)
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
