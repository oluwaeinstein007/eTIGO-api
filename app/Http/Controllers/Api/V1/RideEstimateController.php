<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ride\EstimateRideRequest;
use App\Models\City;
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
        $city = $request->city();

        $result = $this->fareEstimationService->estimateAllClasses(
            $city->id,
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
                'city' => $this->cityPayload($city),
                'estimates' => [],
                'warnings' => $result['warnings'],
            ]);
        }

        // The city the quote is for, decided from the pickup: the app shows
        // it and need not send city_id back when booking.
        $response = [
            'city' => $this->cityPayload($city),
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

    /**
     * @return array{id: string, name: string}
     */
    private function cityPayload(City $city): array
    {
        return ['id' => $city->id, 'name' => $city->name];
    }
}
