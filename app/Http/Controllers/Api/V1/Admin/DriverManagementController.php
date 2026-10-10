<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\DriverStatus;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\VehicleOwnershipType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteDriverOnboardingRequest;
use App\Http\Requests\Admin\ReviewDocumentRequest;
use App\Http\Requests\Admin\ReviewDriverRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\DriverResource;
use App\Http\Resources\VehicleResource;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\VehicleClass;
use App\Services\KycVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Driver::with(['user', 'documents', 'vehicle.vehicleClass', 'city']);

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('kyc_status')) {
            $query->where('kyc_status', $request->input('kyc_status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        $drivers = $query->latest()->paginate(20);

        return response()->json([
            'drivers' => DriverResource::collection($drivers),
            'meta' => [
                'current_page' => $drivers->currentPage(),
                'last_page' => $drivers->lastPage(),
                'per_page' => $drivers->perPage(),
                'total' => $drivers->total(),
            ],
        ]);
    }

    public function show(Driver $driver): JsonResponse
    {
        $driver->load(['user', 'documents', 'vehicle.vehicleClass', 'city', 'kycVerifications']);

        return response()->json([
            'driver' => new DriverResource($driver),
        ]);
    }

    public function review(ReviewDriverRequest $request, Driver $driver): JsonResponse
    {
        if ($driver->status !== DriverStatus::PendingReview) {
            return response()->json([
                'message' => 'Driver can only be reviewed when in pending review status.',
            ], 422);
        }

        $validated = $request->validated();
        $admin = $request->user();
        $oldStatus = $driver->status;

        if ($validated['action'] === 'approve') {
            if (! $driver->isKycVerified()) {
                return response()->json([
                    'message' => 'Cannot approve driver. KYC verification is not complete.',
                    'kyc_status' => $driver->kyc_status,
                ], 422);
            }

            DB::transaction(function () use ($driver, $admin, $oldStatus) {
                $driver->update([
                    'status' => DriverStatus::Approved,
                    'rejection_reason' => null,
                    'approved_at' => now(),
                ]);

                $driver->documents()
                    ->where('status', DocumentStatus::Pending)
                    ->update([
                        'status' => DocumentStatus::Approved,
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => now(),
                    ]);

                AuditLog::record($driver, 'driver_approved', $admin, ['status' => $oldStatus->value], ['status' => 'approved']);
            });

            return response()->json([
                'message' => 'Driver approved successfully.',
                'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city'])),
            ]);
        }

        DB::transaction(function () use ($driver, $admin, $oldStatus, $validated) {
            $driver->update([
                'status' => DriverStatus::Rejected,
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            AuditLog::record($driver, 'driver_rejected', $admin, ['status' => $oldStatus->value], [
                'status' => 'rejected',
                'rejection_reason' => $validated['rejection_reason'],
            ]);
        });

        return response()->json([
            'message' => 'Driver rejected.',
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    public function reviewDocument(ReviewDocumentRequest $request, Driver $driver, DriverDocument $document): JsonResponse
    {
        if ($document->driver_id !== $driver->id) {
            return response()->json(['message' => 'Document does not belong to this driver.'], 404);
        }

        if ($document->status !== DocumentStatus::Pending) {
            return response()->json([
                'message' => 'Only pending documents can be reviewed.',
            ], 422);
        }

        $validated = $request->validated();
        $admin = $request->user();

        $newStatus = $validated['action'] === 'approve'
            ? DocumentStatus::Approved
            : DocumentStatus::Rejected;

        DB::transaction(function () use ($document, $driver, $admin, $validated, $newStatus) {
            $document->update([
                'status' => $newStatus,
                'rejection_reason' => $validated['rejection_reason'] ?? null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $action = $validated['action'] === 'approve' ? 'document_approved' : 'document_rejected';
            AuditLog::record($document, $action, $admin);

            $lockedDriver = Driver::lockForUpdate()->find($driver->id);

            $hasRejectedDocs = $lockedDriver->documents()
                ->where('status', DocumentStatus::Rejected)
                ->exists();

            if ($hasRejectedDocs && $lockedDriver->status === DriverStatus::PendingReview) {
                $allReviewed = ! $lockedDriver->documents()
                    ->where('status', DocumentStatus::Pending)
                    ->exists();

                if ($allReviewed) {
                    $lockedDriver->update([
                        'status' => DriverStatus::Rejected,
                        'rejection_reason' => 'One or more documents were rejected. Please re-upload and resubmit.',
                    ]);

                    AuditLog::record($lockedDriver, 'driver_rejected', $admin);
                }
            }
        });

        return response()->json([
            'message' => "Document {$validated['action']}d successfully.",
            'document' => new DriverDocumentResource($document->fresh()),
        ]);
    }

    public function suspend(Request $request, Driver $driver): JsonResponse
    {
        if ($driver->status !== DriverStatus::Approved) {
            return response()->json([
                'message' => 'Only approved drivers can be suspended.',
            ], 422);
        }

        $admin = $request->user();

        DB::transaction(function () use ($driver, $admin) {
            $driver->update([
                'status' => DriverStatus::Suspended,
                'is_online' => false,
                'suspended_at' => now(),
            ]);

            AuditLog::record($driver, 'driver_suspended', $admin);
        });

        return response()->json([
            'message' => 'Driver suspended successfully.',
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    public function reactivate(Request $request, Driver $driver): JsonResponse
    {
        if ($driver->status !== DriverStatus::Suspended) {
            return response()->json([
                'message' => 'Only suspended drivers can be reactivated.',
            ], 422);
        }

        $admin = $request->user();

        DB::transaction(function () use ($driver, $admin) {
            $driver->update([
                'status' => DriverStatus::Approved,
                'suspended_at' => null,
            ]);

            AuditLog::record($driver, 'driver_reactivated', $admin);
        });

        return response()->json([
            'message' => 'Driver reactivated successfully.',
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    public function toggleFleetVehicle(Request $request, Driver $driver, KycVerificationService $kycService): JsonResponse
    {
        if (! $driver->vehicle) {
            return response()->json(['message' => 'Driver has no registered vehicle.'], 422);
        }

        $vehicle = $driver->vehicle;
        $wasFleet = $vehicle->is_fleet;

        DB::transaction(function () use ($driver, $vehicle, $wasFleet, $request, $kycService) {
            $vehicle->update(['is_fleet' => ! $wasFleet]);

            AuditLog::record(
                $vehicle,
                $wasFleet ? 'vehicle_unmarked_fleet' : 'vehicle_marked_fleet',
                $request->user(),
            );

            $kycService->recalculateDriverKycStatus($driver);
        });

        return response()->json([
            'message' => $wasFleet
                ? 'Vehicle unmarked as fleet. Plate verification now required.'
                : 'Vehicle marked as fleet. Plate verification skipped.',
            'vehicle' => new VehicleResource($vehicle->fresh()),
        ]);
    }

    public function pendingReview(): JsonResponse
    {
        $drivers = Driver::with(['user', 'documents', 'vehicle.vehicleClass', 'city'])
            ->where('status', DriverStatus::PendingReview)
            ->latest()
            ->paginate(20);

        return response()->json([
            'drivers' => DriverResource::collection($drivers),
            'meta' => [
                'current_page' => $drivers->currentPage(),
                'last_page' => $drivers->lastPage(),
                'per_page' => $drivers->perPage(),
                'total' => $drivers->total(),
            ],
        ]);
    }

    /**
     * Complete driver onboarding and approve KYC without external provider checks.
     * Note: Testing/QA only. Remove before production go-live.
     */
    public function completeOnboarding(CompleteDriverOnboardingRequest $request, Driver $driver): JsonResponse
    {
        if ($driver->isSuspended()) {
            return response()->json([
                'message' => 'Driver is currently suspended. Please reactivate the driver instead.',
            ], 422);
        }

        if ($driver->isApproved() && $driver->isKycVerified()) {
            return response()->json([
                'message' => 'Driver onboarding and KYC are already completed and approved.',
                'driver' => new DriverResource($driver->load(['user', 'documents', 'vehicle.vehicleClass', 'city', 'kycVerifications'])),
            ]);
        }

        $missingRequirements = [];

        $ownershipType = $request->input('vehicle_ownership_type')
            ? VehicleOwnershipType::tryFrom($request->input('vehicle_ownership_type'))
            : ($driver->vehicle_ownership_type ?? ($driver->vehicle ? ($driver->vehicle->is_fleet ? VehicleOwnershipType::FleetVehicle : VehicleOwnershipType::OwnVehicle) : null));

        if (! $ownershipType) {
            $missingRequirements[] = 'Vehicle ownership type must be selected.';
        }

        if (! $driver->licence_number) {
            $missingRequirements[] = 'Licence number must be provided.';
        }

        if (! $driver->city_id) {
            $missingRequirements[] = 'City must be selected.';
        }

        $isOwnVehicle = $ownershipType === VehicleOwnershipType::OwnVehicle;
        $isFleetVehicle = $ownershipType === VehicleOwnershipType::FleetVehicle;

        if ($isOwnVehicle && ! $driver->vehicle) {
            $missingRequirements[] = 'Vehicle details must be provided for own-vehicle drivers.';
        }

        $requiredDocTypes = ['driving_licence', 'government_id'];
        if (! $isFleetVehicle) {
            $requiredDocTypes[] = 'vehicle_registration';
            $requiredDocTypes[] = 'insurance_certificate';
        }

        $uploadedDocTypes = $driver->documents()
            ->where('status', '!=', DocumentStatus::Rejected)
            ->pluck('type')
            ->map(fn ($t) => is_object($t) ? $t->value : (string) $t)
            ->toArray();

        $missingDocs = array_diff($requiredDocTypes, $uploadedDocTypes);
        if (! empty($missingDocs)) {
            $missingRequirements[] = 'Missing required documents: '.implode(', ', $missingDocs).'.';
        }

        if (! empty($missingRequirements)) {
            return response()->json([
                'message' => 'Cannot complete onboarding. Driver has not provided all required data.',
                'errors' => $missingRequirements,
            ], 422);
        }

        $admin = $request->user();
        $oldStatus = $driver->status;
        $oldKycStatus = $driver->kyc_status;
        $notes = $request->input('notes', 'Admin manual onboarding and KYC bypass');
        $providedNin = $request->input('nin');

        DB::transaction(function () use ($driver, $admin, $oldStatus, $oldKycStatus, $notes, $providedNin, $ownershipType, $isFleetVehicle) {
            $driver->documents()
                ->whereIn('status', [DocumentStatus::Pending, DocumentStatus::Rejected])
                ->update([
                    'status' => DocumentStatus::Approved,
                    'rejection_reason' => null,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);

            if ($driver->vehicle && ! $driver->vehicle->vehicle_class_id) {
                $defaultClass = VehicleClass::where('is_active', true)->first();
                if ($defaultClass) {
                    $driver->vehicle->update(['vehicle_class_id' => $defaultClass->id]);
                }
            }

            $requiredKycTypes = [
                KycVerificationType::Nin,
                KycVerificationType::DriversLicense,
            ];
            if (! $isFleetVehicle && $driver->vehicle && ! $driver->vehicle->is_fleet) {
                $requiredKycTypes[] = KycVerificationType::VehiclePlate;
            }

            foreach ($requiredKycTypes as $type) {
                $existing = $driver->kycVerifications()->where('type', $type)->latest()->first();

                $idNumber = match ($type) {
                    KycVerificationType::Nin => $providedNin ?? $existing?->id_number ?? 'ADMIN_BYPASS_NIN',
                    KycVerificationType::DriversLicense => $driver->licence_number,
                    KycVerificationType::VehiclePlate => $driver->vehicle?->plate_number ?? 'ADMIN_BYPASS_PLATE',
                    default => 'ADMIN_BYPASS',
                };

                if ($existing) {
                    $existing->update([
                        'id_number' => $idNumber,
                        'status' => KycVerificationStatus::Verified,
                        'provider_reference' => $existing->provider_reference ?? 'admin_bypass',
                        'match_data' => array_merge($existing->match_data ?? [], [
                            'admin_bypass' => true,
                            'bypassed_by' => $admin->id,
                            'bypassed_at' => now()->toIso8601String(),
                            'notes' => $notes,
                        ]),
                        'failure_reason' => null,
                        'verified_at' => now(),
                    ]);
                } else {
                    $driver->kycVerifications()->create([
                        'type' => $type,
                        'id_number' => $idNumber,
                        'provider_reference' => 'admin_bypass',
                        'status' => KycVerificationStatus::Verified,
                        'match_data' => [
                            'admin_bypass' => true,
                            'bypassed_by' => $admin->id,
                            'bypassed_at' => now()->toIso8601String(),
                            'notes' => $notes,
                        ],
                        'verified_at' => now(),
                    ]);
                }
            }

            $driver->update([
                'vehicle_ownership_type' => $ownershipType,
                'status' => DriverStatus::Approved,
                'kyc_status' => KycStatus::Verified,
                'kyc_verified_at' => now(),
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);

            AuditLog::record(
                $driver,
                'driver_onboarding_and_kyc_completed_by_admin',
                $admin,
                [
                    'status' => $oldStatus->value,
                    'kyc_status' => $oldKycStatus->value,
                ],
                [
                    'status' => DriverStatus::Approved->value,
                    'kyc_status' => KycStatus::Verified->value,
                    'notes' => $notes,
                ],
            );
        });

        return response()->json([
            'message' => 'Driver onboarding and KYC marked as completed successfully.',
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city', 'kycVerifications'])),
        ]);
    }
}
