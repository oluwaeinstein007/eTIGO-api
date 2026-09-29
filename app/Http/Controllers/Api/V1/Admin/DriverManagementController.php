<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewDriverRequest;
use App\Http\Resources\DriverResource;
use App\Models\AuditLog;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Driver::with(['user', 'documents', 'vehicle']);

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
        $driver->load(['user', 'documents', 'vehicle']);

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

                AuditLog::record($driver, 'driver_approved', $admin, ['status' => $oldStatus->value], ['status' => 'approved']);
            });

            return response()->json([
                'message' => 'Driver approved successfully.',
                'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle'])),
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
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle'])),
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
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle'])),
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
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle'])),
        ]);
    }

    public function pendingReview(): JsonResponse
    {
        $drivers = Driver::with(['user', 'documents', 'vehicle'])
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
