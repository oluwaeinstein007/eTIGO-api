<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DisputeOutcome;
use App\Enums\DriverStatus;
use App\Enums\SanctionTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfflineFlag\EscalateFlagFormRequest;
use App\Http\Requests\OfflineFlag\ReviewFlagFormRequest;
use App\Http\Resources\OfflineFlagResource;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Services\SanctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOfflineFlagController extends Controller
{
    public function __construct(
        private readonly SanctionService $sanctionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $flags = OfflineTripFlag::query()
            ->with(['driver', 'passenger', 'ride', 'resolvedBy'])
            ->when($request->query('sanction_tier'), function ($query, $tier) {
                $query->where('sanction_tier', $tier);
            })
            ->when($request->query('is_disputed'), function ($query, $disputed) {
                $query->where('is_disputed', filter_var($disputed, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->query('dispute_outcome'), function ($query, $outcome) {
                $query->where('dispute_outcome', $outcome);
            })
            ->when($request->query('driver_id'), function ($query, $driverId) {
                $query->where('driver_id', $driverId);
            })
            ->when($request->query('date_from'), function ($query, $date) {
                $query->where('flagged_at', '>=', $date);
            })
            ->when($request->query('date_to'), function ($query, $date) {
                $query->where('flagged_at', '<=', $date);
            })
            ->when($request->query('status') === 'active', function ($query) {
                $query->active();
            })
            ->when($request->query('status') === 'disputed', function ($query) {
                $query->disputed();
            })
            ->orderBy('flagged_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'message' => 'Offline trip flags retrieved.',
            'flags' => OfflineFlagResource::collection($flags),
            'meta' => [
                'current_page' => $flags->currentPage(),
                'last_page' => $flags->lastPage(),
                'per_page' => $flags->perPage(),
                'total' => $flags->total(),
            ],
        ]);
    }

    public function show(OfflineTripFlag $flag): JsonResponse
    {
        $flag->load(['driver', 'passenger', 'ride.city', 'ride.vehicleClass', 'resolvedBy']);

        return response()->json([
            'message' => 'Offline trip flag details retrieved.',
            'flag' => new OfflineFlagResource($flag),
        ]);
    }

    public function review(ReviewFlagFormRequest $request, OfflineTripFlag $flag): JsonResponse
    {
        $admin = $request->user();
        $outcome = DisputeOutcome::from($request->validated('outcome'));

        $oldValues = [
            'dispute_outcome' => $flag->dispute_outcome?->value,
        ];

        $flag->update([
            'dispute_outcome' => $outcome,
            'dispute_resolved_by_admin_id' => $admin->id,
            'resolved_at' => now(),
        ]);

        if ($outcome === DisputeOutcome::Overturned) {
            $this->sanctionService->reverseSanction($flag);
        }

        AuditLog::record($flag, 'offline_flag_reviewed', $admin, $oldValues, [
            'dispute_outcome' => $outcome->value,
            'resolution_notes' => $request->validated('notes'),
        ]);

        $flag->load(['driver', 'passenger', 'ride', 'resolvedBy']);

        return response()->json([
            'message' => "Dispute {$outcome->label()}.",
            'flag' => new OfflineFlagResource($flag),
        ]);
    }

    public function escalate(EscalateFlagFormRequest $request, OfflineTripFlag $flag): JsonResponse
    {
        $admin = $request->user();

        $oldTier = $flag->sanction_tier;
        $newTier = SanctionTier::from($oldTier->value + 1);

        $flag->update([
            'sanction_tier' => $newTier,
            'sanction_action' => $newTier->action(),
        ]);

        $this->sanctionService->enforceSanction($flag->driver_id, $newTier);

        AuditLog::record($flag, 'offline_flag_escalated', $admin, [
            'sanction_tier' => $oldTier->value,
        ], [
            'sanction_tier' => $newTier->value,
            'notes' => $request->validated('notes'),
        ]);

        $flag->load(['driver', 'passenger', 'ride']);

        return response()->json([
            'message' => "Sanction escalated to {$newTier->label()}.",
            'flag' => new OfflineFlagResource($flag),
        ]);
    }

    public function confirmDeactivation(Request $request, OfflineTripFlag $flag): JsonResponse
    {
        if ($flag->sanction_tier !== SanctionTier::Deactivation) {
            return response()->json([
                'message' => 'Only L3 (deactivation-tier) flags can be confirmed for permanent deactivation.',
            ], 422);
        }

        $driver = Driver::where('user_id', $flag->driver_id)->first();

        if (! $driver) {
            return response()->json(['message' => 'Driver not found.'], 404);
        }

        $admin = $request->user();

        $driver->update([
            'status' => DriverStatus::Deactivated,
            'is_online' => false,
        ]);

        AuditLog::record($flag, 'offline_flag_deactivation_confirmed', $admin, [
            'status' => 'suspended',
        ], [
            'status' => 'deactivated',
            'confirmed_by' => $admin->id,
        ]);

        $flag->load(['driver', 'passenger', 'ride']);

        return response()->json([
            'message' => 'Driver permanently deactivated after admin review.',
            'flag' => new OfflineFlagResource($flag),
        ]);
    }
}
