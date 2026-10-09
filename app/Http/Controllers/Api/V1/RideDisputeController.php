<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\StoreDisputeFormRequest;
use App\Http\Resources\DisputeResource;
use App\Models\AuditLog;
use App\Models\Ride;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RideDisputeController extends Controller
{
    public function store(StoreDisputeFormRequest $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        try {
            $dispute = DB::transaction(function () use ($ride, $user, $request) {
                $dispute = $ride->disputes()->create([
                    'reported_by_user_id' => $user->id,
                    'category' => $request->validated('category'),
                    'description' => $request->validated('description'),
                    'status' => DisputeStatus::Open,
                ]);

                AuditLog::record($ride, 'dispute_filed', $user, null, [
                    'dispute_id' => $dispute->id,
                    'category' => $request->validated('category'),
                ]);

                return $dispute;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['ride' => ['You have already filed a dispute for this ride.']],
            ], 422);
        }

        $dispute->load('reportedBy');

        return response()->json([
            'message' => 'Dispute filed successfully.',
            'dispute' => new DisputeResource($dispute),
        ], 201);
    }
}
