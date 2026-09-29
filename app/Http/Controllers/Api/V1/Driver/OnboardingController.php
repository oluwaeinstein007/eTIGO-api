<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreVehicleRequest;
use App\Http\Requests\Driver\UpdateDriverProfileRequest;
use App\Http\Requests\Driver\UpdateVehicleRequest;
use App\Http\Requests\Driver\UploadDocumentRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\DriverResource;
use App\Http\Resources\VehicleResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class OnboardingController extends Controller
{
    public function status(): JsonResponse
    {
        $user = request()->user();
        $driver = $user->driver()->with(['documents', 'vehicle'])->first();

        if (! $driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        $requiredDocTypes = ['driving_licence', 'vehicle_registration', 'insurance_certificate', 'government_id'];
        $uploadedDocTypes = $driver->documents
            ->where('status', '!==', DocumentStatus::Rejected)
            ->pluck('type.value')
            ->toArray();
        $missingDocTypes = array_diff($requiredDocTypes, $uploadedDocTypes);

        return response()->json([
            'driver' => new DriverResource($driver),
            'onboarding_complete' => empty($missingDocTypes) && $driver->vehicle !== null && $driver->licence_number !== null,
            'missing_documents' => array_values($missingDocTypes),
            'has_vehicle' => $driver->vehicle !== null,
            'has_licence_number' => $driver->licence_number !== null,
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
            'driver' => new DriverResource($driver->fresh(['documents', 'vehicle'])),
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
        $path = $file->store("driver-documents/{$driver->id}", 'local');

        $existing = $driver->documents()
            ->where('type', $request->validated('type'))
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            Storage::disk('local')->delete($existing->file_path);
            $existing->delete();
        }

        $document = $driver->documents()->create([
            'type' => $request->validated('type'),
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);

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

        if ($driver->vehicle) {
            return response()->json(['message' => 'Vehicle already registered. Use the update endpoint.'], 409);
        }

        $vehicle = $driver->vehicle()->create($request->validated());

        AuditLog::record($vehicle, 'vehicle_registered', $user);

        return response()->json([
            'message' => 'Vehicle registered successfully.',
            'vehicle' => new VehicleResource($vehicle),
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
        $oldValues = $vehicle->only(['make', 'model', 'colour', 'plate_number', 'year']);

        $vehicle->update(array_merge($request->validated(), [
            'vehicle_class_approved' => false,
            'class_approved_by' => null,
        ]));

        AuditLog::record($vehicle, 'vehicle_updated', $user, $oldValues, $request->validated());

        return response()->json([
            'message' => 'Vehicle updated successfully. Vehicle class approval has been reset.',
            'vehicle' => new VehicleResource($vehicle->fresh()),
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
            'vehicle' => new VehicleResource($driver->vehicle),
        ]);
    }
}
