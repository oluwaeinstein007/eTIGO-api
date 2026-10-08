<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rating\StoreRatingFormRequest;
use App\Http\Resources\RatingResource;
use App\Models\AuditLog;
use App\Models\Rating;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;

class RideRatingController extends Controller
{
    public function store(StoreRatingFormRequest $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        $ratedUserId = $ride->passenger_id === $user->id
            ? $ride->driver_id
            : $ride->passenger_id;

        $rating = Rating::create([
            'ride_id' => $ride->id,
            'rated_by_user_id' => $user->id,
            'rated_user_id' => $ratedUserId,
            'score' => $request->validated('score'),
            'comment' => $request->validated('comment'),
            'created_at' => now(),
        ]);

        AuditLog::record($ride, 'rating_submitted', $user, null, [
            'score' => $rating->score,
            'rated_user_id' => $ratedUserId,
        ]);

        $rating->load(['ratedBy', 'ratedUser']);

        return response()->json([
            'message' => 'Rating submitted successfully.',
            'rating' => new RatingResource($rating),
        ], 201);
    }
}
