<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\EvReservationStatus;
use App\Enums\MaintenanceItemType;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleMaintenanceItemResource;
use App\Http\Resources\VehicleMaintenanceRecordResource;
use App\Models\EvReservation;
use App\Models\VehicleMaintenanceRecord;
use App\Services\EvReservationFeeService;
use App\Services\VehicleMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The driver's car: maintenance due, papers, odometer, and for plug-in cars
 * the charge level and any charging reservation (OQ-19). Drivers of their
 * own car log service work with proof for operations to approve; fleet
 * cars are kept up to date by E-tiGo operations.
 */
class DriverVehicleStatusController extends Controller
{
    public function __construct(
        private VehicleMaintenanceService $maintenance,
        private EvReservationFeeService $evFees,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;
        $vehicle = $driver?->vehicle;

        if (! $vehicle) {
            return response()->json(['message' => 'Vehicle not found.'], 404);
        }

        $items = $this->maintenance->refresh($vehicle);
        $vehicle->refresh();
        $items->each->setRelation('vehicle', $vehicle);

        $canCharge = (bool) $vehicle->fuel_type?->canCharge();
        $reservation = $canCharge
            ? EvReservation::where('driver_id', $request->user()->id)
                ->whereNotIn('status', [EvReservationStatus::Completed, EvReservationStatus::Expired, EvReservationStatus::Cancelled])
                ->with('station')
                ->latest('reserved_at')
                ->first()
            : null;
        $fee = $canCharge ? $this->evFees->termsFor($request->user()->id) : null;

        return response()->json([
            'vehicle' => [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'fuel_type' => $vehicle->fuel_type?->value,
                'is_fleet' => (bool) $vehicle->is_fleet,
                'shift_mode' => (bool) $vehicle->shift_mode,
                'odometer_km' => $vehicle->odometer_km,
                'odometer_read_at' => $vehicle->odometer_read_at?->toIso8601String(),
                'estimated_odometer_km' => $vehicle->estimatedOdometerKm(),
            ],
            'can_drive' => ! $vehicle->isBlocked(),
            'block_reason' => $vehicle->block_reason,
            // Who records maintenance: the driver (own car, with proof) or operations.
            'records_managed_by' => $vehicle->is_fleet ? 'operations' : 'driver',
            'items' => VehicleMaintenanceItemResource::collection($items),
            'charging' => $canCharge ? [
                'battery_level_percent' => $vehicle->battery_level_percent,
                'battery_level_at' => $vehicle->battery_level_at?->toIso8601String(),
                'active_reservation' => $reservation ? [
                    'id' => $reservation->id,
                    'status' => $reservation->status->value,
                    'station' => $reservation->station ? ['id' => $reservation->station->id, 'name' => $reservation->station->name] : null,
                    'confirmed_slot' => $reservation->confirmed_slot,
                ] : null,
                // What reserving a stall would cost this driver right now.
                'reservation_fee' => [
                    'amount_kobo' => (int) round($fee['fee_amount'] * 100),
                    'waived' => $fee['fee_waived'],
                    'waiver_reason' => $fee['fee_waiver_reason'],
                ],
            ] : null,
            'pending_records' => VehicleMaintenanceRecordResource::collection(
                VehicleMaintenanceRecord::where('vehicle_id', $vehicle->id)->pending()->latest()->get(),
            ),
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $vehicle = $request->user()->driver?->vehicle;

        if (! $vehicle) {
            return response()->json(['message' => 'Vehicle not found.'], 404);
        }

        $records = VehicleMaintenanceRecord::where('vehicle_id', $vehicle->id)
            ->orderByDesc('performed_at')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'records' => VehicleMaintenanceRecordResource::collection($records),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function storeRecord(Request $request): JsonResponse
    {
        $vehicle = $request->user()->driver?->vehicle;

        if (! $vehicle) {
            return response()->json(['message' => 'Vehicle not found.'], 404);
        }

        if ($vehicle->is_fleet) {
            return response()->json(['message' => 'Fleet cars are serviced and recorded by E-tiGo operations. Contact fleet support.'], 403);
        }

        $validated = $request->validate([
            'item_type' => ['required', Rule::in(array_map(fn (MaintenanceItemType $type) => $type->value, MaintenanceItemType::recordable()))],
            'performed_at' => ['required', 'date', 'before_or_equal:today', 'after:'.now()->subYears(2)->toDateString()],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'workshop' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'driver_repair_id' => ['nullable', 'uuid', Rule::exists('driver_repairs', 'id')->where('driver_id', $request->user()->id)],
            // Receipt or workshop report.
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $record = $this->maintenance->submitRecord($vehicle, $request->user(), $validated, $request->file('proof'), byAdmin: false);

        return response()->json([
            'message' => 'Record submitted. E-tiGo will check it shortly.',
            'record' => new VehicleMaintenanceRecordResource($record),
        ], 201);
    }

    public function storeOdometer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'odometer_km' => ['required', 'integer', 'min:0', 'max:2000000'],
        ]);

        $vehicle = $request->user()->driver?->vehicle;

        if (! $vehicle) {
            return response()->json(['message' => 'Vehicle not found.'], 404);
        }

        $vehicle = $this->maintenance->recordOdometer($vehicle, (int) $validated['odometer_km'], $request->user());

        return response()->json([
            'message' => 'Odometer reading saved.',
            'odometer_km' => $vehicle->odometer_km,
            'odometer_read_at' => $vehicle->odometer_read_at?->toIso8601String(),
        ]);
    }
}
