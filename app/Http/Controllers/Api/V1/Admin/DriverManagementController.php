<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\DriverStatus;
use App\Enums\FuelType;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Enums\VehicleOwnershipType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteDriverOnboardingRequest;
use App\Http\Requests\Admin\ReviewDocumentRequest;
use App\Http\Requests\Admin\ReviewDriverRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\DriverResource;
use App\Http\Resources\RideResource;
use App\Http\Resources\VehicleResource;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use App\Services\KycVerificationService;
use App\Services\NotificationService;
use App\Services\VehicleMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DriverManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(DriverStatus::class)],
            'kyc_status' => ['sometimes', 'nullable', Rule::enum(KycStatus::class)],
            'city_id' => ['sometimes', 'nullable', 'uuid'],
            'vehicle_class_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $query = Driver::with(['user', 'documents', 'vehicle.vehicleClass', 'city', 'primaryBankAccount']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('kyc_status')) {
            $query->where('kyc_status', $request->input('kyc_status'));
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', $request->input('city_id'));
        }

        if ($request->filled('vehicle_class_id')) {
            $query->whereHas('vehicle', fn ($q) => $q->where('vehicle_class_id', $request->input('vehicle_class_id')));
        }

        $request->validate(['fuel_type' => ['nullable', Rule::in([...array_column(FuelType::cases(), 'value'), 'none'])]]);

        if ($request->filled('fuel_type')) {
            $fuelType = $request->input('fuel_type');
            $query->whereHas('vehicle', fn ($q) => $fuelType === 'none' ? $q->whereNull('fuel_type') : $q->where('fuel_type', $fuelType));
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

        $drivers = $query->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));

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
        $driver->load(['user', 'documents', 'vehicle.vehicleClass', 'city', 'kycVerifications', 'primaryBankAccount']);

        $completedRides = Ride::where('driver_id', $driver->user_id)->where('status', 'completed');
        $totalRides = Ride::where('driver_id', $driver->user_id);
        $cancelledRides = Ride::where('driver_id', $driver->user_id)->where('status', 'cancelled');

        $totalCount = $totalRides->count();
        $completedCount = $completedRides->count();
        $cancelledCount = $cancelledRides->count();

        $accepted = AuditLog::where('actor_id', $driver->user_id)
            ->where('event', 'ride_accepted')
            ->count();
        $rejected = AuditLog::where('actor_id', $driver->user_id)
            ->where('event', 'ride_rejected')
            ->count();
        $totalOffers = $accepted + $rejected;

        $performance = [
            'rating' => $driver->user?->averageRating(),
            'total_trips' => $totalCount,
            'completed_trips' => $completedCount,
            'cancelled_trips' => $cancelledCount,
            'cancellation_rate' => $totalCount > 0 ? round(($cancelledCount / $totalCount) * 100, 1) : 0,
            'acceptance_rate' => $totalOffers > 0 ? round(($accepted / $totalOffers) * 100, 1) : null,
            'total_earnings' => (float) Ride::where('driver_id', $driver->user_id)
                ->where('status', 'completed')
                ->sum('final_fare_amount'),
        ];

        return response()->json([
            'driver' => new DriverResource($driver),
            'performance' => $performance,
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

    public function reviewDocument(ReviewDocumentRequest $request, Driver $driver, DriverDocument $document, VehicleMaintenanceService $maintenance): JsonResponse
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

        $replacedPaths = [];

        DB::transaction(function () use ($document, $driver, $admin, $validated, $newStatus, &$replacedPaths) {
            $document->update([
                'status' => $newStatus,
                'rejection_reason' => $validated['rejection_reason'] ?? null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                ...(array_key_exists('expires_at', $validated) && $newStatus === DocumentStatus::Approved ? ['expires_at' => $validated['expires_at']] : []),
            ]);

            $action = $validated['action'] === 'approve' ? 'document_approved' : 'document_rejected';
            AuditLog::record($document, $action, $admin);

            // An approved renewal replaces the car's earlier paper of that type.
            if ($newStatus === DocumentStatus::Approved && $document->vehicle_id !== null) {
                $replaced = DriverDocument::where('vehicle_id', $document->vehicle_id)
                    ->where('type', $document->type)
                    ->where('id', '!=', $document->id)
                    ->where('status', DocumentStatus::Approved)
                    ->get();

                $replacedPaths = $replaced->pluck('file_path')->all();
                $replaced->each->delete();
            }

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

        if ($replacedPaths !== []) {
            Storage::disk(config('filesystems.uploads'))->delete($replacedPaths);
        }

        // Vehicle papers decide whether the car may be driven (OQ-19).
        if ($document->vehicle_id !== null && ($vehicle = Vehicle::find($document->vehicle_id)) !== null) {
            $maintenance->refresh($vehicle);
        }

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

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $admin = $request->user();
        $reason = $request->input('reason');

        DB::transaction(function () use ($driver, $admin, $reason) {
            $driver->update([
                'status' => DriverStatus::Suspended,
                'is_online' => false,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ]);

            AuditLog::record($driver, 'driver_suspended', $admin, null, [
                'reason' => $reason,
            ]);
        });

        try {
            app(DriverLocationService::class)->removeDriver($driver->id, $driver->user_id);
        } catch (\Throwable $e) {
            Log::warning('Failed to remove suspended driver from the live location index', [
                'driver_id' => $driver->id,
                'error' => $e->getMessage(),
            ]);
        }

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
                'suspension_reason' => null,
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

    /**
     * Confirm or change the vehicle class a driver operates in. Drivers choose
     * a preferred class during onboarding; after that only E-tiGo may change it.
     */
    public function updateVehicleClass(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_class_id' => ['required_without:fuel_type', 'uuid', Rule::exists('vehicle_classes', 'id')->where('is_active', true)],
            'fuel_type' => ['required_without:vehicle_class_id', Rule::enum(FuelType::class)],
        ]);

        $vehicle = $driver->vehicle;

        if (! $vehicle) {
            return response()->json(['message' => 'Driver has no registered vehicle.'], 422);
        }

        // The class decides the fares of the ride in progress; fuel type does not.
        $hasActiveRide = isset($validated['vehicle_class_id']) && Ride::where('driver_id', $driver->user_id)
            ->whereIn('status', RideStatus::activeStatuses())
            ->exists();

        if ($hasActiveRide) {
            return response()->json([
                'message' => 'The vehicle class cannot be changed while the driver is on an active ride.',
            ], 409);
        }

        $changes = array_filter([
            'vehicle_class_id' => $validated['vehicle_class_id'] ?? null,
            'fuel_type' => $validated['fuel_type'] ?? null,
        ]);

        DB::transaction(function () use ($vehicle, $changes, $request) {
            $old = ['vehicle_class_id' => $vehicle->vehicle_class_id, 'fuel_type' => $vehicle->fuel_type?->value];
            $vehicle->update($changes);

            AuditLog::record(
                $vehicle,
                isset($changes['vehicle_class_id']) ? 'vehicle_class_changed' : 'vehicle_fuel_type_changed',
                $request->user(),
                array_intersect_key($old, $changes),
                $changes,
            );
        });

        return response()->json([
            'message' => isset($changes['vehicle_class_id']) ? 'Vehicle class updated.' : 'Vehicle fuel type updated.',
            'vehicle' => new VehicleResource($vehicle->fresh('vehicleClass')),
        ]);
    }

    /**
     * Move a driver to another city (Phase 2 multi-city, OQ-22). Refused
     * while they are on a trip or hold upcoming reservations, which belong
     * to the old city. An online driver is taken offline, since their
     * location is in the old city's matching pool.
     */
    public function updateCity(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate([
            'city_id' => ['required', 'uuid', Rule::exists('cities', 'id')],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $city = City::findOrFail($validated['city_id']);
        $admin = $request->user();

        // Checked under the driver's row lock, which ride assignment and
        // reservations also take, so neither can slip in during the move.
        $outcome = DB::transaction(function () use ($driver, $city, $admin, $validated) {
            $locked = Driver::lockForUpdate()->findOrFail($driver->id);

            if ($locked->city_id === $city->id) {
                return ['error' => response()->json(['message' => 'The driver is already in this city.'], 422)];
            }

            $hasActiveRide = Ride::where('driver_id', $locked->user_id)
                ->whereIn('status', RideStatus::activeStatuses())
                ->exists();

            if ($hasActiveRide) {
                return ['error' => response()->json([
                    'message' => 'The driver cannot change city during an active ride.',
                ], 409)];
            }

            $reservations = ScheduledRide::upcoming()
                ->where('reserved_driver_id', $locked->user_id)
                ->count();

            if ($reservations > 0) {
                return ['error' => response()->json([
                    'message' => "The driver has {$reservations} upcoming reserved bookings. Release them before changing city.",
                    'upcoming_reservations' => $reservations,
                ], 409)];
            }

            // Shared fleet cars stay in their city, so the driver's shifts
            // on them must be cancelled or given to someone else first.
            $shifts = $locked->shifts()->workable()->where('ends_at', '>', now())->count();

            if ($shifts > 0) {
                return ['error' => response()->json([
                    'message' => "The driver has {$shifts} upcoming fleet shifts. Cancel or reassign them before changing city.",
                    'upcoming_shifts' => $shifts,
                ], 409)];
            }

            $wasOnline = $locked->is_online;
            $oldCityId = $locked->city_id;

            $locked->update([
                'city_id' => $city->id,
                'is_online' => false,
            ]);

            // Plans for the old city would count there as cover that will
            // not come; the driver plans again for the new city.
            $plansRemoved = $locked->availabilityPlans()
                ->where('city_id', $oldCityId)
                ->where('ends_at', '>', now())
                ->delete();

            AuditLog::record(
                $locked,
                'driver_city_changed',
                $admin,
                ['city_id' => $oldCityId],
                ['city_id' => $city->id, 'reason' => $validated['reason'], 'availability_plans_removed' => $plansRemoved],
            );

            return ['was_online' => $wasOnline, 'plans_removed' => $plansRemoved];
        });

        if (isset($outcome['error'])) {
            return $outcome['error'];
        }

        $wasOnline = $outcome['was_online'];
        $driver->refresh();

        if ($wasOnline) {
            try {
                app(DriverLocationService::class)->removeDriver($driver->id, $driver->user_id);
            } catch (\Throwable $e) {
                Log::warning('Failed to remove moved driver from the live location index', [
                    'driver_id' => $driver->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        app(NotificationService::class)->send(
            userId: $driver->user_id,
            type: NotificationType::DriverCityChanged,
            title: 'Your City Changed',
            body: ($city->launch_status->allowsDriversOnline()
                ? "You now drive in {$city->name}. You will get trips there from now on."
                : "You now drive in {$city->name}. E-tiGo has not launched there yet; you can go online from launch day.")
                .($wasOnline ? ' You have been taken offline; go online again when you are in the city.' : ''),
            data: ['city_id' => (string) $city->id],
        );

        $driver->load(['user', 'documents', 'vehicle.vehicleClass', 'city']);

        $warnings = [];
        $vehicleClassId = $driver->vehicle?->vehicle_class_id;
        if ($vehicleClassId && ! $city->vehicleClasses()->wherePivot('is_active', true)->whereKey($vehicleClassId)->exists()) {
            $warnings[] = "{$city->name} does not offer the driver's vehicle class ({$driver->vehicle->vehicleClass?->name}), so they will not get trips until it is offered or their class changes.";
        }
        if (! $city->launch_status->allowsDriversOnline()) {
            $warnings[] = "{$city->name} has not launched yet, so the driver cannot go online until it is live.";
        }
        if ($outcome['plans_removed'] > 0) {
            $warnings[] = "The driver's {$outcome['plans_removed']} upcoming availability plan(s) in their old city were removed.";
        }

        return response()->json([
            'message' => "Driver moved to {$city->name}.",
            'driver' => new DriverResource($driver),
            'warnings' => $warnings,
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
        if (app()->isProduction()) {
            return response()->json([
                'message' => 'Manual onboarding and KYC bypass is disabled in production.',
            ], 403);
        }

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

    public function tripHistory(Request $request, Driver $driver): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = Ride::where('driver_id', $driver->user_id)
            ->with(['passenger', 'city', 'vehicleClass']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $rides = $query->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'rides' => RideResource::collection($rides),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
        ]);
    }
}
