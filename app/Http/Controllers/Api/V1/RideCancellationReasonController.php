<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CancellationReason;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RideCancellationReasonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $role = $request->query('role');
        $user = $request->user() ?? auth('sanctum')->user();

        if (! $role && $user) {
            $role = match (true) {
                $user->isDriver() => 'driver',
                $user->isPassenger() => 'passenger',
                default => null,
            };
        }

        $reasons = array_map(
            fn (CancellationReason $reason) => $reason->toArray(),
            CancellationReason::forRole($role),
        );

        return response()->json([
            'reasons' => $reasons,
        ]);
    }
}
