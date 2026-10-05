<?php

namespace App\Services;

use App\Contracts\KycGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FakeKycGateway implements KycGateway
{
    public function verifyNin(string $ninNumber, array $personalData): array
    {
        Log::info('FakeKycGateway: NIN verification', [
            'nin' => $ninNumber,
            'name' => $personalData['firstname'].' '.$personalData['lastname'],
        ]);

        $verified = ! Str::startsWith($ninNumber, '000');

        return [
            'verified' => $verified,
            'provider_reference' => 'fake_nin_'.Str::random(10),
            'match_data' => [
                'first_name_match' => $verified,
                'last_name_match' => $verified,
                'phone_match' => null,
                'photo_url' => null,
                'gender' => null,
                'birthdate' => null,
            ],
            'raw_response' => ['fake' => true],
            'failure_reason' => $verified ? null : 'Fake: NIN starting with 000 simulates failure',
        ];
    }

    public function verifyDriversLicense(string $licenseNumber, array $personalData): array
    {
        Log::info('FakeKycGateway: License verification', [
            'license' => $licenseNumber,
            'name' => $personalData['firstname'].' '.$personalData['lastname'],
        ]);

        $verified = ! Str::startsWith($licenseNumber, '000');

        return [
            'verified' => $verified,
            'provider_reference' => 'fake_lic_'.Str::random(10),
            'match_data' => [
                'first_name_match' => $verified,
                'last_name_match' => $verified,
                'expiry_date' => now()->addYears(2)->toDateString(),
                'issue_date' => now()->subYears(1)->toDateString(),
                'state_of_issue' => 'Lagos',
                'vehicle_class' => null,
            ],
            'raw_response' => ['fake' => true],
            'failure_reason' => $verified ? null : 'Fake: License starting with 000 simulates failure',
        ];
    }

    public function verifyVehiclePlate(string $plateNumber, array $ownerData): array
    {
        Log::info('FakeKycGateway: Vehicle plate verification', [
            'plate' => $plateNumber,
            'owner' => $ownerData['firstname'].' '.$ownerData['lastname'],
        ]);

        $verified = ! Str::startsWith($plateNumber, '000');

        return [
            'verified' => $verified,
            'provider_reference' => 'fake_plate_'.Str::random(10),
            'match_data' => [
                'first_name_match' => $verified,
                'last_name_match' => $verified,
                'plate_number' => $plateNumber,
                'chassis_number' => $verified ? 'ABC123456789' : null,
                'vehicle_make' => $verified ? 'Toyota' : null,
                'vehicle_model' => $verified ? 'Corolla' : null,
                'vehicle_category' => $verified ? 'Sedan' : null,
                'registered_owner' => $verified
                    ? $ownerData['firstname'].' '.$ownerData['lastname']
                    : 'Unknown Owner',
            ],
            'raw_response' => ['fake' => true],
            'failure_reason' => $verified ? null : 'Fake: Plate starting with 000 simulates failure',
        ];
    }

    public function createLivenessSession(string $reference, string $subjectRef): array
    {
        Log::info('FakeKycGateway: Liveness session created', [
            'reference' => $reference,
            'subject' => $subjectRef,
        ]);

        return [
            'session_id' => 'fake_sess_'.Str::random(16),
            'sdk_token' => 'fake_token_'.Str::random(32),
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
    }

    public function getSessionResult(string $sessionId): array
    {
        Log::info('FakeKycGateway: Session result queried', ['session_id' => $sessionId]);

        $verified = ! Str::contains($sessionId, 'fail');

        return [
            'verified' => $verified,
            'provider_reference' => $sessionId,
            'match_data' => [
                'liveness_passed' => $verified,
                'confidence_score' => $verified ? 0.98 : 0.2,
            ],
            'raw_response' => ['fake' => true],
            'failure_reason' => $verified ? null : 'Fake: Session containing "fail" simulates failure',
        ];
    }
}
