<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\DisputeOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfflineFlag\DisputeFlagFormRequest;
use App\Http\Resources\OfflineFlagResource;
use App\Models\AuditLog;
use App\Models\OfflineTripFlag;
use Illuminate\Http\JsonResponse;

class DriverFlagController extends Controller
{
    public function dispute(DisputeFlagFormRequest $request, OfflineTripFlag $flag): JsonResponse
    {
        $flag->update([
            'is_disputed' => true,
            'dispute_notes' => $request->validated('notes'),
            'dispute_outcome' => DisputeOutcome::Pending,
        ]);

        AuditLog::record($flag, 'offline_flag_disputed', $request->user(), null, [
            'dispute_notes' => $request->validated('notes'),
        ]);

        $flag->load(['ride', 'driver', 'passenger']);

        return response()->json([
            'message' => 'Flag disputed successfully. An admin will review your dispute.',
            'flag' => new OfflineFlagResource($flag),
        ]);
    }
}
