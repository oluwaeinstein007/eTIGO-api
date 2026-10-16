<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ride\EstimateRideRequest;
use App\Services\FareEstimationService;
use App\Services\TierGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class RideEstimateController extends Controller
{
    public function __construct(
        private FareEstimationService $fareEstimationService,
        private TierGateService $tierGateService,
    ) {}

    public function __invoke(EstimateRideRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $result = $this->fareEstimationService->estimateAllClasses(
            $validated['city_id'],
            $validated['pickup_lat'],
            $validated['pickup_lng'],
            $validated['destination_lat'],
            $validated['destination_lng'],
            $user->isPassenger() ? $this->tierGateService->getBookingFeeDiscount($user->id) : 0.0,
            $request->filled('scheduled_pickup_at') ? Carbon::parse($validated['scheduled_pickup_at']) : null,
        );

        if (empty($result['estimates'])) {
            return response()->json([
                'message' => 'No pricing available for this city. Please try a different city.',
                'estimates' => [],
                'warnings' => $result['warnings'],
            ]);
        }

        $response = [
            'estimates' => $result['estimates'],
        ];

        if (! empty($result['warnings'])) {
            $response['warnings'] = $result['warnings'];
        }

        if ($result['cross_city'] !== null) {
            $response['cross_city'] = $result['cross_city'];
        }

        return response()->json($response);
    }
}
