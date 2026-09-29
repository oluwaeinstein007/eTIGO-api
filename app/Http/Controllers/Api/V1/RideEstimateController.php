<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ride\EstimateRideRequest;
use App\Services\FareEstimationService;
use Illuminate\Http\JsonResponse;

class RideEstimateController extends Controller
{
    public function __construct(
        private FareEstimationService $fareEstimationService,
    ) {}

    public function __invoke(EstimateRideRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $estimates = $this->fareEstimationService->estimateAllClasses(
            $validated['city_id'],
            $validated['pickup_lat'],
            $validated['pickup_lng'],
            $validated['destination_lat'],
            $validated['destination_lng'],
        );

        if (empty($estimates)) {
            return response()->json([
                'message' => 'No pricing available for this city. Please try a different city.',
                'estimates' => [],
            ]);
        }

        return response()->json([
            'estimates' => $estimates,
        ]);
    }
}
