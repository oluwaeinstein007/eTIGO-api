<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FleetAgreementStatus;
use App\Enums\FuelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Fleet\AssignFleetVehicleRequest;
use App\Http\Requests\Admin\Fleet\StoreFleetVehicleRequest;
use App\Http\Requests\Admin\Fleet\UpdateFleetVehicleRequest;
use App\Http\Resources\DriverResource;
use App\Http\Resources\VehicleResource;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Services\FleetRemittanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminFleetVehicleController extends Controller
{
    public function __construct(
        private FleetRemittanceService $fleetService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'fuel_type' => ['nullable', Rule::in([...array_column(FuelType::cases(), 'value'), 'none'])],
            'vehicle_class_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(Vehicle::FLEET_STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'unassigned_only' => ['nullable', 'boolean'],
        ]);

        // unassigned_only predates status: cars with no driver, servicing or
        // not. status=available is the list of cars that can be given out.
        $query = $request->boolean('unassigned_only')
            ? Vehicle::fleetInventory()->with(['vehicleClass'])
            : Vehicle::fleet()->with(['driver.user', 'vehicleClass']);

        if ($request->filled('search')) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('make', 'ilike', "%{$search}%")
                    ->orWhere('model', 'ilike', "%{$search}%")
                    ->orWhere('plate_number', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('fuel_type')) {
            $fuelType = $request->input('fuel_type');
            $fuelType === 'none' ? $query->whereNull('fuel_type') : $query->where('fuel_type', $fuelType);
        }

        if ($request->filled('vehicle_class_id')) {
            $query->where('vehicle_class_id', $request->input('vehicle_class_id'));
        }

        if ($request->filled('status')) {
            $query->withFleetStatus($request->input('status'));
        }

        $vehicles = $query->latest()->paginate(20);

        return response()->json([
            'vehicles' => VehicleResource::collection($vehicles),
            'meta' => [
                'current_page' => $vehicles->currentPage(),
                'last_page' => $vehicles->lastPage(),
                'per_page' => $vehicles->perPage(),
                'total' => $vehicles->total(),
            ],
        ]);
    }

    public function store(StoreFleetVehicleRequest $request): JsonResponse
    {
        $vehicle = Vehicle::create([
            ...$request->validated(),
            'is_fleet' => true,
        ]);

        AuditLog::record($vehicle, 'fleet_vehicle_registered', $request->user());

        return response()->json([
            'message' => 'Fleet vehicle registered successfully.',
            'vehicle' => new VehicleResource($vehicle->load('vehicleClass')),
        ], 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        if (! $vehicle->is_fleet) {
            return response()->json(['message' => 'Vehicle is not a fleet vehicle.'], 404);
        }

        $vehicle->load(['driver.user', 'vehicleClass', 'fleetAgreements']);

        return response()->json([
            'vehicle' => new VehicleResource($vehicle),
        ]);
    }

    public function update(UpdateFleetVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        if (! $vehicle->is_fleet) {
            return response()->json(['message' => 'Vehicle is not a fleet vehicle.'], 404);
        }

        $oldValues = $vehicle->only(array_keys($request->validated()));
        $vehicle->update($request->validated());

        AuditLog::record($vehicle, 'fleet_vehicle_updated', $request->user(), $oldValues, $request->validated());

        return response()->json([
            'message' => 'Fleet vehicle updated successfully.',
            'vehicle' => new VehicleResource($vehicle->fresh(['driver.user', 'vehicleClass'])),
        ]);
    }

    public function assign(AssignFleetVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        if (! $vehicle->is_fleet) {
            return response()->json(['message' => 'Vehicle is not a fleet vehicle.'], 404);
        }

        if ($vehicle->isAssigned()) {
            return response()->json(['message' => 'Vehicle is already assigned to a driver.'], 422);
        }

        if ($vehicle->isInMaintenance()) {
            return response()->json(['message' => 'This car is in for maintenance. Mark it back in service before giving it to a driver.'], 422);
        }

        $driver = Driver::findOrFail($request->validated('driver_id'));

        if (! $driver->isApproved()) {
            return response()->json(['message' => 'Driver must be approved before vehicle assignment.'], 422);
        }

        if ($driver->vehicle) {
            return response()->json(['message' => 'Driver already has a vehicle assigned.'], 422);
        }

        try {
            $agreement = $this->fleetService->assignFleetVehicle(
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

        return response()->json([
            'message' => 'Vehicle assigned to driver successfully. Fleet agreement created.',
            'vehicle' => new VehicleResource($vehicle->fresh(['driver.user', 'vehicleClass'])),
            'driver' => new DriverResource($driver->fresh(['user', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    public function unassign(Request $request, Vehicle $vehicle): JsonResponse
    {
        if (! $vehicle->is_fleet) {
            return response()->json(['message' => 'Vehicle is not a fleet vehicle.'], 404);
        }

        if (! $vehicle->isAssigned()) {
            return response()->json(['message' => 'Vehicle is not assigned to any driver.'], 422);
        }

        $activeAgreement = $vehicle->fleetAgreements()
            ->where('status', FleetAgreementStatus::Active)
            ->first();

        if ($activeAgreement) {
            return response()->json([
                'message' => 'Cannot unassign vehicle with an active fleet agreement. Terminate the agreement first.',
            ], 422);
        }

        $driver = $vehicle->driver;
        $vehicle->update(['driver_id' => null]);

        AuditLog::record($vehicle, 'fleet_vehicle_unassigned', $request->user(), [
            'driver_id' => $driver->id,
        ]);

        return response()->json([
            'message' => 'Vehicle unassigned from driver successfully.',
            'vehicle' => new VehicleResource($vehicle->fresh(['vehicleClass'])),
        ]);
    }

    /**
     * Take a fleet car off the road for servicing, or put it back in service.
     * A car in maintenance can't be given to a driver; a car that has a
     * driver keeps them (the driver usually brings it in).
     */
    public function maintenance(Request $request, Vehicle $vehicle): JsonResponse
    {
        if (! $vehicle->is_fleet) {
            return response()->json(['message' => 'Vehicle is not a fleet vehicle.'], 404);
        }

        $validated = $request->validate([
            'in_maintenance' => ['required', 'boolean'],
            'reason' => ['required_if_accepted:in_maintenance', 'nullable', 'string', 'max:500'],
        ]);

        $start = (bool) $validated['in_maintenance'];

        if ($start === $vehicle->isInMaintenance()) {
            return response()->json([
                'message' => $start ? 'This car is already in for maintenance.' : 'This car is not in for maintenance.',
            ], 422);
        }

        $old = $vehicle->only(['maintenance_started_at', 'maintenance_reason']);

        DB::transaction(function () use ($vehicle, $start, $validated, $old, $request) {
            $vehicle->update([
                'maintenance_started_at' => $start ? now() : null,
                'maintenance_reason' => $start ? $validated['reason'] : null,
            ]);

            AuditLog::record(
                $vehicle,
                $start ? 'fleet_vehicle_maintenance_started' : 'fleet_vehicle_maintenance_ended',
                $request->user(),
                $old,
                $start ? ['reason' => $validated['reason']] : ['reason' => $validated['reason'] ?? null],
            );
        });

        return response()->json([
            'message' => $start ? 'Car marked as in for maintenance.' : 'Car is back in service.',
            'vehicle' => new VehicleResource($vehicle->fresh(['driver.user', 'vehicleClass'])),
        ]);
    }

    public function awaitingAssignment(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'city_id' => ['nullable', 'uuid'],
        ]);

        $drivers = Driver::awaitingVehicle()
            ->with(['user', 'documents', 'city'])
            ->when($request->input('search'), function ($query, $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
                $query->where(fn ($q) => $q->where('reference_number', 'ilike', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhereRaw("concat(first_name, ' ', last_name) ilike ?", [$like])
                        ->orWhere('phone', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like)));
            })
            ->when($request->input('city_id'), fn ($query, $cityId) => $query->where('city_id', $cityId))
            ->latest()
            ->paginate(20);

        return response()->json([
            'drivers' => DriverResource::collection($drivers),
            'meta' => [
                'current_page' => $drivers->currentPage(),
                'last_page' => $drivers->lastPage(),
                'per_page' => $drivers->perPage(),
                'total' => $drivers->total(),
            ],
        ]);
    }
}
