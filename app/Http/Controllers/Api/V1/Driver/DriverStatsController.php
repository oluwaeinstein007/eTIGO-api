<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverStatsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $averageRating = DB::table('ratings')
            ->where('rated_user_id', $user->id)
            ->avg('score');

        return response()->json([
            'stats' => [
                'completed_trips' => $user->driverRides()->where('status', 'completed')->count(),
                'average_rating' => $averageRating === null ? null : round((float) $averageRating, 2),
                'ratings_count' => DB::table('ratings')->where('rated_user_id', $user->id)->count(),
            ],
        ]);
    }
}
