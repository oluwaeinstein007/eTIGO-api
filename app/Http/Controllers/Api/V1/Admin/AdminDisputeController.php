<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\ResolveDisputeFormRequest;
use App\Http\Resources\DisputeResource;
use App\Models\AuditLog;
use App\Models\Dispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDisputeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Dispute::with(['reportedBy', 'resolvedBy', 'ride']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('ride_id')) {
            $query->where('ride_id', $request->input('ride_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ilike', "%{$search}%")
                    ->orWhereHas('reportedBy', function ($q) use ($search) {
                        $q->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $disputes = $query->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json([
            'disputes' => DisputeResource::collection($disputes),
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'last_page' => $disputes->lastPage(),
                'per_page' => $disputes->perPage(),
                'total' => $disputes->total(),
            ],
        ]);
    }

    public function show(Dispute $dispute): JsonResponse
    {
        $dispute->load([
            'reportedBy',
            'resolvedBy',
            'ride.passenger',
            'ride.driver',
            'ride.vehicleClass',
            'ride.city',
            'ride.payment',
        ]);

        return response()->json([
            'dispute' => new DisputeResource($dispute),
        ]);
    }

    public function resolve(ResolveDisputeFormRequest $request, Dispute $dispute): JsonResponse
    {
        $admin = $request->user();
        $oldStatus = $dispute->status;

        $dispute->update([
            'status' => $request->validated('status'),
            'resolution_notes' => $request->validated('resolution_notes'),
            'resolved_by_admin_id' => $admin->id,
            'resolved_at' => now(),
        ]);

        AuditLog::record($dispute, 'dispute_resolved', $admin, [
            'status' => $oldStatus->value,
        ], [
            'status' => $request->validated('status'),
            'resolution_notes' => $request->validated('resolution_notes'),
        ]);

        $dispute->load(['reportedBy', 'resolvedBy', 'ride']);

        return response()->json([
            'message' => 'Dispute resolved successfully.',
            'dispute' => new DisputeResource($dispute),
        ]);
    }
}
