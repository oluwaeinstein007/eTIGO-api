<?php

namespace App\Services;

use App\Contracts\KycGateway;
use Illuminate\Support\Str;

class FakeKycGateway implements KycGateway
{
    /**
     * @param  array{firstname: string, lastname: string, dob?: string, phone?: string}  $personalData
     * @return array{verified: bool, provider_reference: string, match_data: array, raw_response: array, failure_reason: ?string}
     */
    public function verifyNin(string $ninNumber, array $personalData): array
    {
        $ref = 'mock_nin_'.Str::uuid();

        return [
            'verified' => true,
            'provider_reference' => $ref,
            'match_data' => [
                'firstname' => $personalData['firstname'] ?? '',
                'lastname' => $personalData['lastname'] ?? '',
                'phone' => $personalData['phone'] ?? null,
                'nin' => $ninNumber,
                'simulated' => true,
                'verified_at' => now()->toIso8601String(),
            ],
            'raw_response' => [
                'status' => 'success',
                'message' => 'NIN verification simulated successfully (external provider skipped via configuration).',
                'simulated' => true,
            ],
            'failure_reason' => null,
        ];
    }

    /**
     * @param  array{firstname: string, lastname: string, dob?: string}  $personalData
     * @return array{verified: bool, provider_reference: string, match_data: array, raw_response: array, failure_reason: ?string}
     */
    public function verifyDriversLicense(string $licenseNumber, array $personalData): array
    {
        $ref = 'mock_dl_'.Str::uuid();

        return [
            'verified' => true,
            'provider_reference' => $ref,
            'match_data' => [
                'firstname' => $personalData['firstname'] ?? '',
                'lastname' => $personalData['lastname'] ?? '',
                'license_number' => $licenseNumber,
                'simulated' => true,
                'verified_at' => now()->toIso8601String(),
            ],
            'raw_response' => [
                'status' => 'success',
                'message' => 'Driver license verification simulated successfully (external provider skipped via configuration).',
                'simulated' => true,
            ],
            'failure_reason' => null,
        ];
    }

    /**
     * @param  array{firstname: string, lastname: string}  $ownerData
     * @return array{verified: bool, provider_reference: string, match_data: array, raw_response: array, failure_reason: ?string}
     */
    public function verifyVehiclePlate(string $plateNumber, array $ownerData): array
    {
        $ref = 'mock_plate_'.Str::uuid();

        return [
            'verified' => true,
            'provider_reference' => $ref,
            'match_data' => [
                'plate_number' => $plateNumber,
                'firstname' => $ownerData['firstname'] ?? '',
                'lastname' => $ownerData['lastname'] ?? '',
                'simulated' => true,
                'verified_at' => now()->toIso8601String(),
            ],
            'raw_response' => [
                'status' => 'success',
                'message' => 'Vehicle plate verification simulated successfully (external provider skipped via configuration).',
                'simulated' => true,
            ],
            'failure_reason' => null,
        ];
    }

    /**
     * @return array{session_id: string, sdk_token: string, expires_at: string}
     */
    public function createLivenessSession(string $reference, string $subjectRef): array
    {
        $sessionId = 'mock_liveness_'.Str::uuid();

        return [
            'session_id' => $sessionId,
            'sdk_token' => 'mock_sdk_token_'.Str::random(32),
            'expires_at' => now()->addHours(2)->toIso8601String(),
        ];
    }

    /**
     * @return array{verified: bool, provider_reference: string, match_data: array, raw_response: array, failure_reason: ?string}
     */
    public function getSessionResult(string $sessionId): array
    {
        return [
            'verified' => true,
            'provider_reference' => $sessionId,
            'match_data' => [
                'liveness_score' => 99.8,
                'simulated' => true,
                'verified_at' => now()->toIso8601String(),
            ],
            'raw_response' => [
                'status' => 'success',
                'message' => 'Liveness check simulated successfully (external provider skipped via configuration).',
                'simulated' => true,
            ],
            'failure_reason' => null,
        ];
    }
}
