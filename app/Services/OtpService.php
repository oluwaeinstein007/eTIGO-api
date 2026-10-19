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

    /**
     * The ride PIN must stay valid for the whole pickup phase — matching, a
     * possible passenger retry, and the drive to the pickup point — so it is
     * tied to the ride lifecycle rather than a short OTP-style window.
     */
    private const PIN_EXPIRY_MINUTES = 360;

    private const RIDE_MAX_ATTEMPTS = 3;

    public function __construct(
        private SmsGateway $smsGateway,
    ) {}

    /**
     * @param  string  $purpose  'login' for sign-in, or a sensitive action such as 'bank_account_change'
     * @return array{sent: bool, error: ?string, expires_at: ?string}
     */
    public function sendAuthOtp(string $phone, string $purpose = 'login'): array
    {
        $recentOtp = OtpCode::forPhone($phone)
            ->where('purpose', $purpose)
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
            ->where('purpose', $purpose)
            ->active()
            ->update(['expires_at' => now()]);

        $useStaticOtp = ! app()->isProduction();
        $plainCode = $useStaticOtp ? self::STATIC_OTP : $this->generateCode(self::AUTH_OTP_LENGTH);

        $otp = OtpCode::create([
            'phone' => $phone,
            'code' => hash('sha256', $plainCode),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(self::AUTH_OTP_EXPIRY_MINUTES),
        ]);

        if (! $useStaticOtp) {
            $sent = $this->smsGateway->sendOtp($phone, $plainCode, $this->authOtpMessage($plainCode, $purpose));

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
     * @param  string|null  $forType  The account type (passenger/driver) the caller is signing in as;
     *                                a new user may only register that type with this verification
     * @return array{valid: bool, phone: ?string, error: ?string}
     */
    public function verifyAuthOtp(string $phone, string $code, ?string $forType = null, string $purpose = 'login'): array
    {
        $otpCode = OtpCode::forPhone($phone)
            ->where('purpose', $purpose)
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

        $otpCode->markAsVerified($forType);

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

    /**
     * The SMS text. It names what the code is for, so a code someone tricks
     * a user into reading out is recognisable. Plain ASCII keeps it to one
     * SMS part.
     */
    private function authOtpMessage(string $code, string $purpose): string
    {
        $what = match ($purpose) {
            'bank_account_change' => 'code to change your payout bank account',
            default => 'verification code',
        };

        return "Your E-tiGo {$what} is {$code}. It expires in ".self::AUTH_OTP_EXPIRY_MINUTES.' minutes. Do not share it with anyone.';
    }

    private function generateCode(int $length): string
    {
        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
