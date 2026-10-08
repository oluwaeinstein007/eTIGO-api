<?php

namespace App\Services;

use App\Contracts\KycGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class QoreIdKycGateway implements KycGateway
{
    private string $baseUrl = 'https://api.qoreid.com/v1';

    public function __construct(
        private string $clientId,
        private string $secretKey,
    ) {}

    public function verifyNin(string $ninNumber, array $personalData): array
    {
        $response = $this->authenticatedRequest(
            'post',
            "/ng/identities/nin-premium/{$ninNumber}",
            array_filter([
                'firstname' => $personalData['firstname'],
                'lastname' => $personalData['lastname'],
                'dob' => $personalData['dob'] ?? null,
                'phone' => $personalData['phone'] ?? null,
            ]),
        );

        $data = $response['data'] ?? $response;

        return [
            'verified' => $this->isIdentityMatch($data, $personalData),
            'provider_reference' => $data['id'] ?? $ninNumber,
            'match_data' => $this->extractNinMatchData($data, $personalData),
            'raw_response' => $data,
            'failure_reason' => $this->isIdentityMatch($data, $personalData) ? null : 'Name mismatch against NIN records',
        ];
    }

    public function verifyDriversLicense(string $licenseNumber, array $personalData): array
    {
        $response = $this->authenticatedRequest(
            'post',
            "/ng/identities/drivers-license/{$licenseNumber}",
            array_filter([
                'firstname' => $personalData['firstname'],
                'lastname' => $personalData['lastname'],
                'dob' => $personalData['dob'] ?? null,
            ]),
        );

        $data = $response['data'] ?? $response;

        return [
            'verified' => $this->isIdentityMatch($data, $personalData),
            'provider_reference' => $data['id'] ?? $licenseNumber,
            'match_data' => $this->extractLicenseMatchData($data, $personalData),
            'raw_response' => $data,
            'failure_reason' => $this->isIdentityMatch($data, $personalData) ? null : 'Name mismatch against license records',
        ];
    }

    public function verifyVehiclePlate(string $plateNumber, array $ownerData): array
    {
        $response = $this->authenticatedRequest(
            'post',
            "/ng/identities/license-plate-basic/{$plateNumber}",
            array_filter([
                'firstname' => $ownerData['firstname'],
                'lastname' => $ownerData['lastname'],
            ]),
        );

        $data = $response['data'] ?? $response;
        $summary = $data['summary'] ?? [];
        $verified = ($summary['firstname_match'] ?? '') === 'EXACT_MATCH'
            || ($summary['lastname_match'] ?? '') === 'EXACT_MATCH'
            || $this->isIdentityMatch($data, $ownerData);

        return [
            'verified' => $verified,
            'provider_reference' => $data['id'] ?? $plateNumber,
            'match_data' => $this->extractPlateMatchData($data, $ownerData),
            'raw_response' => $data,
            'failure_reason' => $verified ? null : 'Vehicle plate owner does not match driver name',
        ];
    }

    public function createLivenessSession(string $reference, string $subjectRef): array
    {
        $response = $this->sessionRequest('/sessions', [
            'type' => 'collection',
            'productCode' => 'liveness',
            'reference' => $reference,
            'subjectRef' => $subjectRef,
        ]);

        return [
            'session_id' => $response['sessionId'],
            'sdk_token' => $response['sdkSessionToken'],
            'expires_at' => $response['expiresAt'],
        ];
    }

    public function getSessionResult(string $sessionId): array
    {
        $response = $this->authenticatedRequest('get', "/sessions/{$sessionId}");

        $data = $response['data'] ?? $response;
        $status = $data['status'] ?? 'unknown';
        $verified = $status === 'completed' || $status === 'verified';

        return [
            'verified' => $verified,
            'provider_reference' => $sessionId,
            'match_data' => [
                'liveness_passed' => $verified,
                'confidence_score' => $data['confidence'] ?? $data['score'] ?? null,
            ],
            'raw_response' => $data,
            'failure_reason' => $verified ? null : ($data['reason'] ?? 'Liveness check did not pass'),
        ];
    }

    private function isIdentityMatch(array $data, array $personalData): bool
    {
        $responseFirstName = strtolower(trim($data['firstname'] ?? $data['first_name'] ?? ''));
        $responseLastName = strtolower(trim($data['lastname'] ?? $data['last_name'] ?? ''));
        $inputFirstName = strtolower(trim($personalData['firstname']));
        $inputLastName = strtolower(trim($personalData['lastname']));

        $firstNameMatch = str_contains($responseFirstName, $inputFirstName)
            || str_contains($inputFirstName, $responseFirstName);

        $lastNameMatch = str_contains($responseLastName, $inputLastName)
            || str_contains($inputLastName, $responseLastName);

        return $firstNameMatch && $lastNameMatch;
    }

    private function extractNinMatchData(array $data, array $personalData): array
    {
        return [
            'first_name_match' => $this->fuzzyMatch(
                $data['firstname'] ?? $data['first_name'] ?? '',
                $personalData['firstname'],
            ),
            'last_name_match' => $this->fuzzyMatch(
                $data['lastname'] ?? $data['last_name'] ?? '',
                $personalData['lastname'],
            ),
            'phone_match' => isset($personalData['phone'], $data['phone'])
                ? $data['phone'] === $personalData['phone']
                : null,
            'photo_url' => $data['photo'] ?? $data['image'] ?? null,
            'gender' => $data['gender'] ?? null,
            'birthdate' => $data['birthdate'] ?? $data['dob'] ?? null,
        ];
    }

    private function extractLicenseMatchData(array $data, array $personalData): array
    {
        return [
            'first_name_match' => $this->fuzzyMatch(
                $data['firstname'] ?? $data['first_name'] ?? '',
                $personalData['firstname'],
            ),
            'last_name_match' => $this->fuzzyMatch(
                $data['lastname'] ?? $data['last_name'] ?? '',
                $personalData['lastname'],
            ),
            'expiry_date' => $data['expiryDate'] ?? $data['expiry_date'] ?? null,
            'issue_date' => $data['issueDate'] ?? $data['issue_date'] ?? null,
            'state_of_issue' => $data['stateOfIssue'] ?? $data['state_of_issue'] ?? null,
            'vehicle_class' => $data['vehicleClass'] ?? $data['vehicle_class'] ?? null,
        ];
    }

    private function extractPlateMatchData(array $data, array $ownerData): array
    {
        $summary = $data['summary'] ?? [];

        return [
            'first_name_match' => ($summary['firstname_match'] ?? null) === 'EXACT_MATCH'
                || $this->fuzzyMatch(
                    $data['firstname'] ?? $data['first_name'] ?? '',
                    $ownerData['firstname'],
                ),
            'last_name_match' => ($summary['lastname_match'] ?? null) === 'EXACT_MATCH'
                || $this->fuzzyMatch(
                    $data['lastname'] ?? $data['last_name'] ?? '',
                    $ownerData['lastname'],
                ),
            'plate_number' => $data['plateNumber'] ?? $data['plate_number'] ?? null,
            'chassis_number' => $data['chassisNumber'] ?? $data['chassis_number'] ?? null,
            'vehicle_make' => $data['make'] ?? $data['vehicleMake'] ?? null,
            'vehicle_model' => $data['model'] ?? $data['vehicleModel'] ?? null,
            'vehicle_category' => $data['category'] ?? $data['vehicleCategory'] ?? null,
            'registered_owner' => trim(
                ($data['firstname'] ?? $data['first_name'] ?? '').' '.
                ($data['lastname'] ?? $data['last_name'] ?? ''),
            ) ?: null,
        ];
    }

    private function fuzzyMatch(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        return $a === $b || str_contains($a, $b) || str_contains($b, $a);
    }

    /**
     * Session creation uses Basic Auth (clientId:secret).
     */
    private function sessionRequest(string $path, array $data): array
    {
        $response = Http::withBasicAuth($this->clientId, $this->secretKey)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5)
            ->post("{$this->baseUrl}{$path}", $data);

        if ($response->failed()) {
            throw new RuntimeException(
                'QoreID session error: '.($response->json('message') ?? $response->body()),
            );
        }

        return $response->json();
    }

    /**
     * Identity verification endpoints use OAuth2 bearer token.
     */
    private function authenticatedRequest(string $method, string $path, array $data = []): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->{$method}("{$this->baseUrl}{$path}", $data);

        if ($response->failed()) {
            throw new RuntimeException(
                'QoreID API error: '.($response->json('message') ?? $response->body()),
            );
        }

        return $response->json();
    }

    private function getAccessToken(): string
    {
        $cacheKey = 'qoreid_access_token';

        return cache()->remember($cacheKey, 3500, function () {
            $response = Http::acceptJson()
                ->timeout(10)
                ->post('https://api.qoreid.com/token', [
                    'clientId' => $this->clientId,
                    'secret' => $this->secretKey,
                ]);

            if ($response->failed()) {
                throw new RuntimeException('QoreID token error: '.$response->body());
            }

            return $response->json('accessToken') ?? $response->json('access_token');
        });
    }
}
