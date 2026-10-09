<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\DocumentStatus;
use App\Enums\DriverStatus;
use App\Enums\VehicleOwnershipType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreVehicleRequest;
use App\Http\Requests\Driver\UpdateDriverProfileRequest;
use App\Http\Requests\Driver\UpdateVehicleRequest;
use App\Http\Requests\Driver\UploadDocumentRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\DriverResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VehicleResource;
use App\Jobs\VerifyVehiclePlateJob;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;

class OnboardingController extends Controller
{
    public function status(): JsonResponse
    {
        $user = request()->user();
        $driver = $user->driver()->with(['documents', 'vehicle.vehicleClass', 'city'])->first();

        if (! $driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        $requiredDocTypes = $this->getRequiredDocumentTypes($driver);
        $uploadedDocTypes = $driver->documents
            ->where('status', '!==', DocumentStatus::Rejected)
            ->pluck('type.value')
            ->toArray();
        $missingDocTypes = array_diff($requiredDocTypes, $uploadedDocTypes);

        $needsVehicle = $driver->isOwnVehicle();
        $hasVehicleOwnershipType = $driver->vehicle_ownership_type !== null;

        $onboardingComplete = empty($missingDocTypes)
            && $hasVehicleOwnershipType
            && (! $needsVehicle || $driver->vehicle !== null)
            && $driver->licence_number !== null
            && $driver->city_id !== null;

        return response()->json([
            'driver' => new DriverResource($driver),
            'onboarding_complete' => $onboardingComplete,
            'can_submit' => $onboardingComplete && \in_array($driver->status, [DriverStatus::Onboarding, DriverStatus::Rejected]),
            'missing_documents' => array_values($missingDocTypes),
            'has_vehicle_ownership_type' => $hasVehicleOwnershipType,
            'vehicle_ownership_type' => $driver->vehicle_ownership_type,
            'has_vehicle' => $driver->vehicle !== null,
            'needs_vehicle_registration' => $needsVehicle,
            'has_licence_number' => $driver->licence_number !== null,
            'has_city' => $driver->city_id !== null,
            'kyc_status' => $driver->kyc_status,
            'kyc_verified_at' => $driver->kyc_verified_at,
        ]);
    }

    public function setVehicleOwnership(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_ownership_type' => ['required', new Enum(VehicleOwnershipType::class)],
        ]);

        $user = $request->user();
        $driver = $user->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        if (! \in_array($driver->status, [DriverStatus::Onboarding, DriverStatus::Rejected])) {
            return response()->json([
                'message' => 'Vehicle ownership type can only be set during onboarding.',
            ], 422);
        }

        $driver->update([
            'vehicle_ownership_type' => $request->input('vehicle_ownership_type'),
        ]);

        AuditLog::record($driver, 'vehicle_ownership_set', $user, null, [
            'vehicle_ownership_type' => $request->input('vehicle_ownership_type'),
        ]);

        return response()->json([
            'message' => 'Vehicle ownership type set successfully.',
            'driver' => new DriverResource($driver->fresh(['documents', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    public function updateProfile(UpdateDriverProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $driver->update($request->validated());

        AuditLog::record($driver, 'profile_updated', $user);

        return response()->json([
            'message' => 'Driver profile updated successfully.',
            'driver' => new DriverResource($driver->fresh(['documents', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    public function uploadDocument(UploadDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $file = $request->file('document');
        $path = $file->store("driver-documents/{$driver->id}", config('filesystems.uploads'));

        if (! $path) {
            return response()->json(['message' => 'File upload failed. Please try again.'], 503);
        }

        $existing = $driver->documents()
            ->where('type', $request->validated('type'))
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        $oldFilePath = $existing?->file_path;

        try {
            $document = DB::transaction(function () use ($driver, $request, $path, $file, $existing) {
                if ($existing) {
                    $existing->delete();
                }

                return $driver->documents()->create([
                    'type' => $request->validated('type'),
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'expires_at' => $request->validated('expires_at'),
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk(config('filesystems.uploads'))->delete($path);

            throw $e;
        }

        if ($oldFilePath) {
            Storage::disk(config('filesystems.uploads'))->delete($oldFilePath);
        }

        AuditLog::record($document, 'document_uploaded', $user);

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => new DriverDocumentResource($document),
        ], 201);
    }

    public function documents(): JsonResponse
    {
        $user = request()->user();
        $driver = $user->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        return response()->json([
            'documents' => DriverDocumentResource::collection($driver->documents),
        ]);
    }

    public function storeVehicle(StoreVehicleRequest $request): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        if ($driver->isFleetVehicle()) {
            return response()->json([
                'message' => 'Fleet vehicle drivers cannot register their own vehicle. A vehicle will be assigned by admin.',
            ], 422);
        }

        if ($driver->vehicle) {
            return response()->json(['message' => 'Vehicle already registered. Use the update endpoint.'], 409);
        }

        $vehicle = $driver->vehicle()->create($request->validated());

        AuditLog::record($vehicle, 'vehicle_registered', $user);

        if (! $vehicle->is_fleet) {
            VerifyVehiclePlateJob::dispatch($driver, $vehicle->plate_number);
        }

        $message = $vehicle->is_fleet
            ? 'Vehicle registered successfully. Fleet vehicle — plate verification skipped.'
            : 'Vehicle registered successfully. Plate verification initiated.';

        return response()->json([
            'message' => $message,
            'vehicle' => new VehicleResource($vehicle->load('vehicleClass')),
        ], 201);
    }

    public function updateVehicle(UpdateVehicleRequest $request): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver || ! $driver->vehicle) {
            return response()->json(['message' => 'Vehicle not found.'], 404);
        }

        $vehicle = $driver->vehicle;
        $oldPlate = $vehicle->plate_number;
        $oldValues = $vehicle->only(['make', 'model', 'colour', 'plate_number', 'year']);

        $vehicle->update($request->validated());

        AuditLog::record($vehicle, 'vehicle_updated', $user, $oldValues, $request->validated());

        $newPlate = $vehicle->fresh()->plate_number;
        if ($newPlate !== $oldPlate && ! $vehicle->fresh()->is_fleet) {
            $driver->kycVerifications()
                ->where('type', 'vehicle_plate')
                ->whereIn('status', ['verified', 'processing'])
                ->update(['status' => 'expired']);

            VerifyVehiclePlateJob::dispatch($driver, $newPlate);
        }

        return response()->json([
            'message' => $newPlate !== $oldPlate
                ? 'Vehicle updated successfully. Plate re-verification initiated.'
                : 'Vehicle updated successfully.',
            'vehicle' => new VehicleResource($vehicle->fresh('vehicleClass')),
        ]);
    }

    public function vehicle(): JsonResponse
    {
        $user = request()->user();
        $driver = $user->driver;

        if (! $driver || ! $driver->vehicle) {
            return response()->json(['message' => 'Vehicle not found.'], 404);
        }

        return response()->json([
            'vehicle' => new VehicleResource($driver->vehicle->load('vehicleClass')),
        ]);
    }

    public function updateProfilePhoto(Request $request): JsonResponse
    {
        $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $user = $request->user();

        if ($user->profile_photo_path) {
            Storage::disk(config('filesystems.uploads'))->delete($user->profile_photo_path);
        }

        $path = $request->file('profile_photo')
            ->store("profile-photos/{$user->id}", config('filesystems.uploads'));

        if (! $path) {
            return response()->json(['message' => 'File upload failed. Please try again.'], 503);
        }

        $user->update(['profile_photo_path' => $path]);

        AuditLog::record($user, 'profile_photo_updated', $user);

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function deleteProfilePhoto(): JsonResponse
    {
        $user = request()->user();

        if (! $user->profile_photo_path) {
            return response()->json([
                'message' => 'No profile photo to remove.',
            ], 422);
        }

        Storage::disk(config('filesystems.uploads'))->delete($user->profile_photo_path);

        $user->update(['profile_photo_path' => null]);

        AuditLog::record($user, 'profile_photo_removed', $user);

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function submitForReview(): JsonResponse
    {
        $user = request()->user();
        $driver = $user->driver()->with(['documents', 'vehicle'])->first();

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        if (! \in_array($driver->status, [DriverStatus::Onboarding, DriverStatus::Rejected])) {
            return response()->json([
                'message' => 'Application can only be submitted from onboarding or rejected status.',
            ], 422);
        }

        $errors = [];

        if (! $driver->vehicle_ownership_type) {
            $errors[] = 'Vehicle ownership type must be selected.';
        }

        $requiredDocTypes = $this->getRequiredDocumentTypes($driver);
        $uploadedDocTypes = $driver->documents
            ->where('status', '!==', DocumentStatus::Rejected)
            ->pluck('type.value')
            ->toArray();
        $missingDocTypes = array_diff($requiredDocTypes, $uploadedDocTypes);

        if (! empty($missingDocTypes)) {
            $errors[] = 'Missing documents: '.implode(', ', $missingDocTypes);
        }

        if ($driver->isOwnVehicle() && ! $driver->vehicle) {
            $errors[] = 'Vehicle information is required.';
        }

        if (! $driver->licence_number) {
            $errors[] = 'Licence number is required.';
        }

        if (! $driver->city_id) {
            $errors[] = 'City selection is required.';
        }

        if (! empty($errors)) {
            return response()->json([
                'message' => 'Cannot submit application. Please complete all required steps.',
                'errors' => $errors,
            ], 422);
        }

        $driver->update([
            'status' => DriverStatus::PendingReview,
            'rejection_reason' => null,
        ]);

        AuditLog::record($driver, 'application_submitted', $user);

        return response()->json([
            'message' => 'Application submitted for review.',
            'driver' => new DriverResource($driver->fresh(['documents', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    private function getRequiredDocumentTypes(\App\Models\Driver $driver): array
    {
        $types = ['driving_licence', 'government_id'];

        if (! $driver->isFleetVehicle()) {
            $types[] = 'vehicle_registration';
            $types[] = 'insurance_certificate';
        }

        return $types;
    }
}
