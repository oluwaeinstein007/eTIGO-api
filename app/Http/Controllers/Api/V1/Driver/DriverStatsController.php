<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DriverStatsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $averageRating = DB::table('ratings')
            ->where('rated_user_id', $user->id)
            ->avg('score');
        $now = Carbon::now();
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();
        $weeklyEarnings = $user->driverRides()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$weekStart, $weekEnd])
            ->sum('final_fare_amount');

        return response()->json([
            'stats' => [
                'completed_trips' => $user->driverRides()->where('status', 'completed')->count(),
                'average_rating' => $averageRating === null ? null : round((float) $averageRating, 2),
                'ratings_count' => DB::table('ratings')->where('rated_user_id', $user->id)->count(),
                'earnings_this_week' => (float) $weeklyEarnings,
                'earnings_currency' => 'NGN',
            ],
        ]);
    }
}
