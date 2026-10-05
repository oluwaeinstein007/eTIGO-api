<?php

namespace App\Services;

use App\Contracts\KycGateway;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Models\Driver;
use App\Models\KycVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class KycVerificationService
{
    public function __construct(
        private KycGateway $gateway,
    ) {}

    public function verifyNin(Driver $driver, string $ninNumber): KycVerification
    {
        $this->ensureNoDuplicateVerification($driver, KycVerificationType::Nin);

        $user = $driver->user;

        return DB::transaction(function () use ($driver, $ninNumber, $user) {
            $verification = $driver->kycVerifications()->create([
                'type' => KycVerificationType::Nin,
                'id_number' => $ninNumber,
                'status' => KycVerificationStatus::Processing,
            ]);

            $this->updateDriverKycStatus($driver, KycStatus::InProgress);

            try {
                $result = $this->gateway->verifyNin($ninNumber, [
                    'firstname' => $user->first_name,
                    'lastname' => $user->last_name,
                    'phone' => $user->phone,
                ]);

                $verification->update([
                    'provider_reference' => $result['provider_reference'],
                    'status' => $result['verified']
                        ? KycVerificationStatus::Verified
                        : KycVerificationStatus::Failed,
                    'match_data' => $result['match_data'],
                    'provider_response' => $result['raw_response'],
                    'failure_reason' => $result['failure_reason'],
                    'verified_at' => $result['verified'] ? now() : null,
                ]);
            } catch (\Throwable $e) {
                Log::error('KYC NIN verification failed', [
                    'driver_id' => $driver->id,
                    'error' => $e->getMessage(),
                ]);

                $verification->update([
                    'status' => KycVerificationStatus::Failed,
                    'failure_reason' => 'Provider error: '.$e->getMessage(),
                ]);
            }

            $this->recalculateDriverKycStatus($driver);

            return $verification->fresh();
        });
    }

    public function verifyDriversLicense(Driver $driver, string $licenseNumber): KycVerification
    {
        $this->ensureNoDuplicateVerification($driver, KycVerificationType::DriversLicense);

        $user = $driver->user;

        return DB::transaction(function () use ($driver, $licenseNumber, $user) {
            $verification = $driver->kycVerifications()->create([
                'type' => KycVerificationType::DriversLicense,
                'id_number' => $licenseNumber,
                'status' => KycVerificationStatus::Processing,
            ]);

            $this->updateDriverKycStatus($driver, KycStatus::InProgress);

            try {
                $result = $this->gateway->verifyDriversLicense($licenseNumber, [
                    'firstname' => $user->first_name,
                    'lastname' => $user->last_name,
                ]);

                $verification->update([
                    'provider_reference' => $result['provider_reference'],
                    'status' => $result['verified']
                        ? KycVerificationStatus::Verified
                        : KycVerificationStatus::Failed,
                    'match_data' => $result['match_data'],
                    'provider_response' => $result['raw_response'],
                    'failure_reason' => $result['failure_reason'],
                    'verified_at' => $result['verified'] ? now() : null,
                ]);
            } catch (\Throwable $e) {
                Log::error('KYC license verification failed', [
                    'driver_id' => $driver->id,
                    'error' => $e->getMessage(),
                ]);

                $verification->update([
                    'status' => KycVerificationStatus::Failed,
                    'failure_reason' => 'Provider error: '.$e->getMessage(),
                ]);
            }

            $this->recalculateDriverKycStatus($driver);

            return $verification->fresh();
        });
    }

    public function verifyVehiclePlate(Driver $driver, string $plateNumber): KycVerification
    {
        $this->ensureNoDuplicateVerification($driver, KycVerificationType::VehiclePlate);

        $user = $driver->user;

        return DB::transaction(function () use ($driver, $plateNumber, $user) {
            $verification = $driver->kycVerifications()->create([
                'type' => KycVerificationType::VehiclePlate,
                'id_number' => $plateNumber,
                'status' => KycVerificationStatus::Processing,
            ]);

            $this->updateDriverKycStatus($driver, KycStatus::InProgress);

            try {
                $result = $this->gateway->verifyVehiclePlate($plateNumber, [
                    'firstname' => $user->first_name,
                    'lastname' => $user->last_name,
                ]);

                $verification->update([
                    'provider_reference' => $result['provider_reference'],
                    'status' => $result['verified']
                        ? KycVerificationStatus::Verified
                        : KycVerificationStatus::Failed,
                    'match_data' => $result['match_data'],
                    'provider_response' => $result['raw_response'],
                    'failure_reason' => $result['failure_reason'],
                    'verified_at' => $result['verified'] ? now() : null,
                ]);
            } catch (\Throwable $e) {
                Log::error('KYC vehicle plate verification failed', [
                    'driver_id' => $driver->id,
                    'error' => $e->getMessage(),
                ]);

                $verification->update([
                    'status' => KycVerificationStatus::Failed,
                    'failure_reason' => 'Provider error: '.$e->getMessage(),
                ]);
            }

            $this->recalculateDriverKycStatus($driver);

            return $verification->fresh();
        });
    }

    public function createLivenessSession(Driver $driver): KycVerification
    {
        $this->ensureNoDuplicateVerification($driver, KycVerificationType::Liveness);

        return DB::transaction(function () use ($driver) {
            $reference = 'etigo_kyc_'.$driver->id.'_'.now()->timestamp;
            $subjectRef = 'driver_'.$driver->id;

            $verification = $driver->kycVerifications()->create([
                'type' => KycVerificationType::Liveness,
                'status' => KycVerificationStatus::Pending,
            ]);

            $this->updateDriverKycStatus($driver, KycStatus::InProgress);

            try {
                $session = $this->gateway->createLivenessSession($reference, $subjectRef);

                $verification->update([
                    'provider_reference' => $session['session_id'],
                    'status' => KycVerificationStatus::Processing,
                    'match_data' => ['sdk_token' => $session['sdk_token']],
                    'expires_at' => $session['expires_at'],
                ]);
            } catch (\Throwable $e) {
                Log::error('KYC liveness session creation failed', [
                    'driver_id' => $driver->id,
                    'error' => $e->getMessage(),
                ]);

                $verification->update([
                    'status' => KycVerificationStatus::Failed,
                    'failure_reason' => 'Provider error: '.$e->getMessage(),
                ]);
            }

            return $verification->fresh();
        });
    }

    public function processLivenessResult(string $sessionId): ?KycVerification
    {
        $verification = KycVerification::where('provider_reference', $sessionId)
            ->where('type', KycVerificationType::Liveness)
            ->where('status', KycVerificationStatus::Processing)
            ->first();

        if (! $verification) {
            return null;
        }

        try {
            $result = $this->gateway->getSessionResult($sessionId);

            $verification->update([
                'status' => $result['verified']
                    ? KycVerificationStatus::Verified
                    : KycVerificationStatus::Failed,
                'match_data' => $result['match_data'],
                'provider_response' => $result['raw_response'],
                'failure_reason' => $result['failure_reason'],
                'verified_at' => $result['verified'] ? now() : null,
            ]);

            $this->recalculateDriverKycStatus($verification->driver);
        } catch (\Throwable $e) {
            Log::error('KYC liveness result processing failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            $verification->update([
                'status' => KycVerificationStatus::Failed,
                'failure_reason' => 'Provider error: '.$e->getMessage(),
            ]);
        }

        return $verification->fresh();
    }

    public function getDriverVerificationSummary(Driver $driver): array
    {
        $verifications = $driver->kycVerifications()
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('type.value');

        $requiredTypes = $this->getRequiredVerificationTypes($driver);

        $summary = [];
        foreach (KycVerificationType::cases() as $type) {
            $latest = $verifications->get($type->value)?->first();
            $summary[$type->value] = [
                'status' => $latest?->status->value ?? 'not_started',
                'verified_at' => $latest?->verified_at,
                'failure_reason' => $latest?->failure_reason,
                'required' => in_array($type, $requiredTypes),
            ];
        }

        return $summary;
    }

    public function getRequiredVerificationTypes(Driver $driver): array
    {
        $types = [
            KycVerificationType::Nin,
            KycVerificationType::DriversLicense,
        ];

        $vehicle = $driver->vehicle;
        if (! $vehicle || ! $vehicle->is_fleet) {
            $types[] = KycVerificationType::VehiclePlate;
        }

        return $types;
    }

    public function recalculateDriverKycStatus(Driver $driver): void
    {
        $requiredTypes = $this->getRequiredVerificationTypes($driver);

        $verifications = $driver->kycVerifications()
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('type.value');

        $allVerified = true;
        $anyFailed = false;
        $anyStarted = false;

        foreach ($requiredTypes as $type) {
            $latest = $verifications->get($type->value)?->first();

            if (! $latest) {
                $allVerified = false;

                continue;
            }

            $anyStarted = true;

            if ($latest->status === KycVerificationStatus::Failed) {
                $anyFailed = true;
                $allVerified = false;
            } elseif ($latest->status !== KycVerificationStatus::Verified) {
                $allVerified = false;
            }
        }

        if ($allVerified) {
            $this->updateDriverKycStatus($driver, KycStatus::Verified);
            $driver->update(['kyc_verified_at' => now()]);
        } elseif ($anyFailed) {
            $this->updateDriverKycStatus($driver, KycStatus::Failed);
        } elseif ($anyStarted) {
            $this->updateDriverKycStatus($driver, KycStatus::InProgress);
        }
    }

    private function updateDriverKycStatus(Driver $driver, KycStatus $status): void
    {
        $driver->update(['kyc_status' => $status]);
    }

    private function ensureNoDuplicateVerification(Driver $driver, KycVerificationType $type): void
    {
        $existing = $driver->kycVerifications()
            ->where('type', $type)
            ->whereIn('status', [
                KycVerificationStatus::Verified,
                KycVerificationStatus::Processing,
            ])
            ->exists();

        if ($existing) {
            throw new RuntimeException(
                "A {$type->label()} verification is already completed or in progress.",
            );
        }
    }
}
