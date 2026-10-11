<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EvStationResource;
use App\Models\EvChargingStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvStationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'city_id' => ['required', 'uuid', 'exists:cities,id'],
        ]);

        $stations = EvChargingStation::query()
            ->with('stalls')
            ->active()
            ->forCity($request->query('city_id'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'stations' => EvStationResource::collection($stations),
        ]);
    }

    public function show(EvChargingStation $station): JsonResponse
    {
        $station->load(['stalls', 'city']);

        return response()->json([
            'station' => new EvStationResource($station),
        ]);
    }
}
