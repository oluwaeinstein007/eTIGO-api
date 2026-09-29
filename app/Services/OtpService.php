<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use App\Models\OtpCode;

class OtpService
{
    private const OTP_LENGTH = 6;

    private const OTP_EXPIRY_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private SmsGateway $smsGateway,
    ) {}

    public function requestOtp(string $phone, string $purpose = 'login'): OtpCode
    {
        $recentOtp = OtpCode::forPhone($phone)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))
            ->active()
            ->first();

        if ($recentOtp) {
            return $recentOtp;
        }

        OtpCode::forPhone($phone)
            ->where('purpose', $purpose)
            ->active()
            ->update(['expires_at' => now()]);

        $code = $this->generateCode();

        $otpCode = OtpCode::create([
            'phone' => $phone,
            'code' => $code,
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(self::OTP_EXPIRY_MINUTES),
        ]);

        $this->smsGateway->send(
            $phone,
            "Your E-tiGo verification code is: {$code}. Valid for ".self::OTP_EXPIRY_MINUTES.' minutes.',
        );

        return $otpCode;
    }

    /**
     * @return array{valid: bool, otp: ?OtpCode, error: ?string}
     */
    public function verifyOtp(string $phone, string $code, string $purpose = 'login'): array
    {
        $otpCode = OtpCode::forPhone($phone)
            ->where('purpose', $purpose)
            ->active()
            ->latest()
            ->first();

        if (! $otpCode) {
            return ['valid' => false, 'otp' => null, 'error' => 'No active OTP found. Please request a new code.'];
        }

        if ($otpCode->hasExceededMaxAttempts(self::MAX_ATTEMPTS)) {
            return ['valid' => false, 'otp' => $otpCode, 'error' => 'Maximum verification attempts exceeded. Please request a new code.'];
        }

        if ($otpCode->isExpired()) {
            return ['valid' => false, 'otp' => $otpCode, 'error' => 'OTP has expired. Please request a new code.'];
        }

        if ($otpCode->code !== $code) {
            $otpCode->incrementAttempts();

            return ['valid' => false, 'otp' => $otpCode, 'error' => 'Invalid verification code.'];
        }

        $otpCode->markAsVerified();

        return ['valid' => true, 'otp' => $otpCode, 'error' => null];
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 10 ** self::OTP_LENGTH - 1), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }
}
