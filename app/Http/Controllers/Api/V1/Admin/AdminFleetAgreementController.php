<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Fleet\StoreFleetAgreementRequest;
use App\Http\Requests\Admin\Fleet\TerminateFleetAgreementRequest;
use App\Http\Requests\Admin\Fleet\UpdateFleetAgreementRequest;
use App\Http\Resources\FleetAgreementResource;
use App\Models\Driver;
use App\Models\FleetAgreement;
use App\Models\Vehicle;
use App\Services\FleetRemittanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFleetAgreementController extends Controller
{
    public function __construct(
        private FleetRemittanceService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = FleetAgreement::with(['driver.user', 'vehicle']);

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('driver_id')) {
            $query->where('driver_id', $request->input('driver_id'));
        }

        $agreements = $query->latest()->paginate(20);

        return response()->json([
            'agreements' => FleetAgreementResource::collection($agreements),
            'meta' => [
                'current_page' => $agreements->currentPage(),
                'last_page' => $agreements->lastPage(),
                'per_page' => $agreements->perPage(),
                'total' => $agreements->total(),
            ],
        ]);
    }

    public function store(StoreFleetAgreementRequest $request): JsonResponse
    {
        $driver = Driver::findOrFail($request->validated('driver_id'));
        $vehicle = Vehicle::findOrFail($request->validated('vehicle_id'));

        try {
            $agreement = $this->service->createAgreement(
                driver: $driver,
                vehicle: $vehicle,
                dailyTarget: $request->validated('daily_remittance_target'),
                totalVehicleCost: $request->validated('total_vehicle_cost'),
                startDate: $request->validated('agreement_start_date'),
                admin: $request->user(),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $agreement->load(['driver.user', 'vehicle']);

        return response()->json([
            'message' => 'Fleet agreement created successfully.',
            'agreement' => new FleetAgreementResource($agreement),
        ], 201);
    }

    public function show(FleetAgreement $fleetAgreement): JsonResponse
    {
        $fleetAgreement->load(['driver.user', 'vehicle', 'dailyRemittances' => fn ($q) => $q->latest('date')->limit(30)]);

        return response()->json([
            'agreement' => new FleetAgreementResource($fleetAgreement),
        ]);
    }

    public function update(UpdateFleetAgreementRequest $request, FleetAgreement $fleetAgreement): JsonResponse
    {
        try {
            $agreement = $this->service->updateAgreement(
                agreement: $fleetAgreement,
                data: $request->validated(),
                admin: $request->user(),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $agreement->load(['driver.user', 'vehicle']);

        return response()->json([
            'message' => 'Fleet agreement updated successfully.',
            'agreement' => new FleetAgreementResource($agreement),
        ]);
    }

    public function terminate(TerminateFleetAgreementRequest $request, FleetAgreement $fleetAgreement): JsonResponse
    {
        try {
            $agreement = $this->service->terminateAgreement(
                agreement: $fleetAgreement,
                reason: $request->validated('reason'),
                admin: $request->user(),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Fleet agreement terminated.',
            'agreement' => new FleetAgreementResource($agreement),
        ]);
    }

    public function pause(FleetAgreement $fleetAgreement, Request $request): JsonResponse
    {
        try {
            $agreement = $this->service->pauseAgreement($fleetAgreement, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Fleet agreement paused.',
            'agreement' => new FleetAgreementResource($agreement),
        ]);
    }

    public function resume(FleetAgreement $fleetAgreement, Request $request): JsonResponse
    {
        try {
            $agreement = $this->service->resumeAgreement($fleetAgreement, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Fleet agreement resumed.',
            'agreement' => new FleetAgreementResource($agreement),
        ]);
    }
}
