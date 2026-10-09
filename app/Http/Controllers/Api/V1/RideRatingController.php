<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rating\StoreRatingFormRequest;
use App\Http\Resources\RatingResource;
use App\Models\AuditLog;
use App\Models\Rating;
use App\Models\Ride;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RideRatingController extends Controller
{
    public function store(StoreRatingFormRequest $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        $ratedUserId = $ride->passenger_id === $user->id
            ? $ride->driver_id
            : $ride->passenger_id;

        try {
            $rating = DB::transaction(function () use ($ride, $user, $ratedUserId, $request) {
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

                return $rating;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['ride' => ['You have already rated this ride.']],
            ], 422);
        }

        $rating->load(['ratedBy', 'ratedUser']);

        return response()->json([
            'message' => 'Rating submitted successfully.',
            'rating' => new RatingResource($rating),
        ], 201);
    }
}
