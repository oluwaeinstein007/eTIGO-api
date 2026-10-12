<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PassengerDisputeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $disputes = Dispute::where('reported_by_user_id', $user->id)
            ->with(['ride', 'reportedBy'])
            ->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 15), 1), 50));

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

    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $user = $request->user();

        if ($dispute->reported_by_user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $dispute->load(['ride', 'reportedBy']);

        return response()->json([
            'dispute' => new DisputeResource($dispute),
        ]);
    }
}
