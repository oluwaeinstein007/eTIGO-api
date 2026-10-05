<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\VerifyDriversLicenseRequest;
use App\Http\Requests\Driver\VerifyNinRequest;
use App\Http\Requests\Driver\VerifyVehiclePlateRequest;
use App\Http\Resources\KycVerificationResource;
use App\Models\AuditLog;
use App\Services\KycVerificationService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class KycController extends Controller
{
    public function __construct(
        private KycVerificationService $kycService,
    ) {}

    public function status(): JsonResponse
    {
        $driver = request()->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $summary = $this->kycService->getDriverVerificationSummary($driver);

        return response()->json([
            'kyc_status' => $driver->kyc_status,
            'kyc_verified_at' => $driver->kyc_verified_at,
            'verifications' => $summary,
        ]);
    }

    public function verifyNin(VerifyNinRequest $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        try {
            $verification = $this->kycService->verifyNin(
                $driver,
                $request->validated('nin_number'),
            );

            AuditLog::record($verification, 'kyc_nin_verification_submitted', $request->user());

            return response()->json([
                'message' => $verification->isVerified()
                    ? 'NIN verified successfully.'
                    : 'NIN verification failed: '.($verification->failure_reason ?? 'Unknown reason'),
                'verification' => new KycVerificationResource($verification),
            ], $verification->isVerified() ? 200 : 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function verifyDriversLicense(VerifyDriversLicenseRequest $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        try {
            $verification = $this->kycService->verifyDriversLicense(
                $driver,
                $request->validated('license_number'),
            );

            AuditLog::record($verification, 'kyc_license_verification_submitted', $request->user());

            return response()->json([
                'message' => $verification->isVerified()
                    ? "Driver's license verified successfully."
                    : 'License verification failed: '.($verification->failure_reason ?? 'Unknown reason'),
                'verification' => new KycVerificationResource($verification),
            ], $verification->isVerified() ? 200 : 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function verifyVehiclePlate(VerifyVehiclePlateRequest $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        if (! $driver->vehicle) {
            return response()->json(['message' => 'Register a vehicle before verifying its plate.'], 422);
        }

        try {
            $plateNumber = $request->validated('plate_number');

            $verification = $this->kycService->verifyVehiclePlate($driver, $plateNumber);

            AuditLog::record($verification, 'kyc_vehicle_plate_verification_submitted', $request->user());

            return response()->json([
                'message' => $verification->isVerified()
                    ? 'Vehicle plate verified successfully.'
                    : 'Vehicle plate verification failed: '.($verification->failure_reason ?? 'Unknown reason'),
                'verification' => new KycVerificationResource($verification),
            ], $verification->isVerified() ? 200 : 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function createLivenessSession(): JsonResponse
    {
        $driver = request()->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        try {
            $verification = $this->kycService->createLivenessSession($driver);

            AuditLog::record($verification, 'kyc_liveness_session_created', request()->user());

            $sdkToken = $verification->match_data['sdk_token'] ?? null;

            return response()->json([
                'message' => 'Liveness session created. Use the SDK token in the mobile app.',
                'session_id' => $verification->provider_reference,
                'sdk_token' => $sdkToken,
                'expires_at' => $verification->expires_at,
                'verification' => new KycVerificationResource($verification),
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function verifications(): JsonResponse
    {
        $driver = request()->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $verifications = $driver->kycVerifications()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'verifications' => KycVerificationResource::collection($verifications),
        ]);
    }
}
