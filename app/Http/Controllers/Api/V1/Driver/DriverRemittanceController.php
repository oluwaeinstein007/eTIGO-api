<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailyRemittanceResource;
use App\Http\Resources\FleetAgreementResource;
use App\Services\FleetRemittanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverRemittanceController extends Controller
{
    public function __construct(
        private FleetRemittanceService $service,
    ) {}

    public function today(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $remittance = $this->service->getDriverTodayRemittance($driver);

        if (! $remittance) {
            return response()->json([
                'message' => 'No active fleet agreement.',
                'remittance' => null,
            ]);
        }

        return response()->json([
            'remittance' => new DailyRemittanceResource($remittance),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $remittances = $this->service->getDriverRemittanceHistory($driver);

        return response()->json([
            'remittances' => DailyRemittanceResource::collection($remittances),
            'meta' => [
                'current_page' => $remittances->currentPage(),
                'last_page' => $remittances->lastPage(),
                'per_page' => $remittances->perPage(),
                'total' => $remittances->total(),
            ],
        ]);
    }

    public function agreement(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $agreement = $driver->activeFleetAgreement;

        if (! $agreement) {
            return response()->json([
                'message' => 'No active fleet agreement.',
                'agreement' => null,
            ]);
        }

        $agreement->load('vehicle');

        return response()->json([
            'agreement' => new FleetAgreementResource($agreement),
        ]);
    }
}
