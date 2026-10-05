<?php

namespace App\Contracts;

interface KycGateway
{
    /**
     * Verify a National Identification Number against the holder's name.
     *
     * @param  array{firstname: string, lastname: string, dob?: string, phone?: string}  $personalData
     * @return array{verified: bool, provider_reference: string, match_data: array, raw_response: array, failure_reason: ?string}
     */
    public function verifyNin(string $ninNumber, array $personalData): array;

    /**
     * Verify a driver's license number against the holder's name.
     *
     * @param  array{firstname: string, lastname: string, dob?: string}  $personalData
     * @return array{verified: bool, provider_reference: string, match_data: array, raw_response: array, failure_reason: ?string}
     */
    public function verifyDriversLicense(string $licenseNumber, array $personalData): array;

    /**
     * Verify a vehicle plate number against the registered owner's name.
     *
     * @param  array{firstname: string, lastname: string}  $ownerData
     * @return array{verified: bool, provider_reference: string, match_data: array, raw_response: array, failure_reason: ?string}
     */
    public function verifyVehiclePlate(string $plateNumber, array $ownerData): array;

    /**
     * Create a liveness check session for the mobile SDK.
     *
     * @return array{session_id: string, sdk_token: string, expires_at: string}
     */
    public function createLivenessSession(string $reference, string $subjectRef): array;

    /**
     * Retrieve the result of a liveness/verification session.
     *
     * @return array{verified: bool, provider_reference: string, match_data: array, raw_response: array, failure_reason: ?string}
     */
    public function getSessionResult(string $sessionId): array;
}
