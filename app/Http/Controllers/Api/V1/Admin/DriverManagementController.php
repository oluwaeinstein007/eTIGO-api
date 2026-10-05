<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewDocumentRequest;
use App\Http\Requests\Admin\ReviewDriverRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\DriverResource;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverDocument;
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
        $driver->load(['user', 'documents', 'vehicle.vehicleClass', 'city']);

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

        $document->update([
            'status' => $newStatus,
            'rejection_reason' => $validated['rejection_reason'] ?? null,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $action = $validated['action'] === 'approve' ? 'document_approved' : 'document_rejected';
        AuditLog::record($document, $action, $admin);

        $hasRejectedDocs = $driver->documents()
            ->where('status', DocumentStatus::Rejected)
            ->exists();

        if ($hasRejectedDocs && $driver->status === DriverStatus::PendingReview) {
            $allReviewed = ! $driver->documents()
                ->where('status', DocumentStatus::Pending)
                ->exists();

            if ($allReviewed) {
                $driver->update([
                    'status' => DriverStatus::Rejected,
                    'rejection_reason' => 'One or more documents were rejected. Please re-upload and resubmit.',
                ]);

                AuditLog::record($driver, 'driver_rejected', $admin);
            }
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
}
