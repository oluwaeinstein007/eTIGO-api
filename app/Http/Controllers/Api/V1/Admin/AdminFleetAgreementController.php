<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FleetAgreementStatus;
use App\Exceptions\FleetAgreementFieldException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Fleet\ExcuseRemittanceDayRequest;
use App\Http\Requests\Admin\Fleet\PauseFleetAgreementRequest;
use App\Http\Requests\Admin\Fleet\StoreFleetAgreementRequest;
use App\Http\Requests\Admin\Fleet\SwapFleetVehicleRequest;
use App\Http\Requests\Admin\Fleet\TerminateFleetAgreementRequest;
use App\Http\Requests\Admin\Fleet\TerminateWithSettlementRequest;
use App\Http\Requests\Admin\Fleet\UpdateFleetAgreementRequest;
use App\Http\Requests\Admin\Fleet\WithdrawRemittanceExcuseRequest;
use App\Http\Resources\DailyRemittanceResource;
use App\Http\Resources\FleetAgreementResource;
use App\Models\AuditLog;
use App\Models\DailyRemittance;
use App\Models\Driver;
use App\Models\FleetAgreement;
use App\Models\Vehicle;
use App\Services\FleetRemittanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminFleetAgreementController extends Controller
{
    private const HISTORY_DESCRIPTIONS = [
        'fleet_agreement.created' => 'Agreement created',
        'fleet_agreement.updated' => 'Agreement changed',
        'fleet_agreement.paused' => 'Agreement paused',
        'fleet_agreement.resumed' => 'Agreement resumed',
        'fleet_agreement.terminated' => 'Agreement terminated',
        'fleet_agreement.terminated_with_settlement' => 'Agreement terminated with a settlement',
        'fleet_agreement.completed' => 'Car paid off',
        'fleet_vehicle_swapped' => 'Car swapped; this agreement replaced the previous one',
        'remittance_day_excused' => 'Day excused',
        'remittance_day_excuse_withdrawn' => 'Excuse withdrawn',
        'fleet_vehicle_assigned' => 'Car handed to the driver',
        'fleet_vehicle_unassigned' => 'Car taken back from the driver',
    ];

    public function __construct(
        private FleetRemittanceService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(FleetAgreementStatus::class)],
            'driver_id' => ['nullable', 'uuid'],
            'vehicle_class_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = FleetAgreement::with(['driver.user', 'vehicle.vehicleClass'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('driver_id'), fn ($q, $driverId) => $q->where('driver_id', $driverId))
            ->when($request->input('vehicle_class_id'), fn ($q, $classId) => $q->whereHas('vehicle', fn ($v) => $v->where('vehicle_class_id', $classId)))
            ->when($request->input('search'), function ($q, $search) {
                // Agreement reference, driver name, phone or reference, or the car's plate.
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $q->where(fn ($q) => $q->where('reference_number', 'ilike', $like)
                    ->orWhereHas('vehicle', fn ($v) => $v->where('plate_number', 'ilike', $like))
                    ->orWhereHas('driver', fn ($d) => $d->where('reference_number', 'ilike', $like)
                        ->orWhereHas('user', fn ($u) => $u->where('first_name', 'ilike', $like)
                            ->orWhere('last_name', 'ilike', $like)
                            ->orWhereRaw("concat(first_name, ' ', last_name) ilike ?", [$like])
                            ->orWhere('phone', 'ilike', $like))));
            });

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

        $agreement->load(['driver.user', 'vehicle.vehicleClass']);

        return response()->json([
            'message' => 'Fleet agreement created successfully.',
            'agreement' => new FleetAgreementResource($agreement),
        ], 201);
    }

    public function show(FleetAgreement $fleetAgreement): JsonResponse
    {
        $fleetAgreement->load(['driver.user', 'vehicle.vehicleClass', 'dailyRemittances' => fn ($q) => $q->latest('date')->limit(30)]);

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
        } catch (FleetAgreementFieldException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $agreement->load(['driver.user', 'vehicle.vehicleClass']);

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

    public function pause(PauseFleetAgreementRequest $request, FleetAgreement $fleetAgreement): JsonResponse
    {
        try {
            $agreement = $this->service->pauseAgreement($fleetAgreement, $request->user(), $request->validated('reason'));
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

    public function swap(SwapFleetVehicleRequest $request, FleetAgreement $fleetAgreement): JsonResponse
    {
        $newVehicle = Vehicle::findOrFail($request->validated('new_vehicle_id'));

        try {
            $agreement = $this->service->swapVehicle(
                currentAgreement: $fleetAgreement,
                newVehicle: $newVehicle,
                reason: $request->validated('reason'),
                carryOverRemitted: $request->boolean('carry_over_remitted'),
                newDailyTarget: $request->validated('daily_remittance_target'),
                newTotalCost: $request->validated('total_vehicle_cost'),
                admin: $request->user(),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $agreement->load(['driver.user', 'vehicle.vehicleClass']);

        return response()->json([
            'message' => 'Vehicle swapped successfully. Previous agreement terminated, new agreement created.',
            'agreement' => new FleetAgreementResource($agreement),
        ]);
    }

    public function terminateWithSettlement(
        TerminateWithSettlementRequest $request,
        FleetAgreement $fleetAgreement,
    ): JsonResponse {
        try {
            $agreement = $this->service->terminateWithSettlement(
                agreement: $fleetAgreement,
                reason: $request->validated('reason'),
                admin: $request->user(),
                vehicleReturnStatus: $request->validated('vehicle_return_status'),
                outstandingAmount: (float) $request->validated('outstanding_amount', 0),
                settlementAmount: (float) $request->validated('settlement_amount', 0),
                settlementNotes: $request->validated('settlement_notes'),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Fleet agreement terminated with settlement recorded.',
            'agreement' => new FleetAgreementResource($agreement),
        ]);
    }

    public function excuseDay(
        ExcuseRemittanceDayRequest $request,
        FleetAgreement $fleetAgreement,
        DailyRemittance $remittance,
    ): JsonResponse {
        if ($remittance->agreement_id !== $fleetAgreement->id) {
            return response()->json(['message' => 'Remittance does not belong to this agreement.'], 422);
        }

        $reason = $request->validated('reason');
        if ($request->validated('notes')) {
            $reason .= ': '.$request->validated('notes');
        }

        try {
            $remittance = $this->service->excuseDay($remittance, $reason, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Remittance day marked as excused.',
            'remittance' => new DailyRemittanceResource($remittance),
        ]);
    }

    /**
     * Take back an excuse given by mistake.
     */
    public function withdrawExcuse(
        WithdrawRemittanceExcuseRequest $request,
        FleetAgreement $fleetAgreement,
        DailyRemittance $remittance,
    ): JsonResponse {
        if ($remittance->agreement_id !== $fleetAgreement->id) {
            return response()->json(['message' => 'Remittance does not belong to this agreement.'], 422);
        }

        try {
            $remittance = $this->service->unexcuseDay($remittance, $request->validated('reason'), $request->user());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Excuse withdrawn. The day counts towards the target again.',
            'remittance' => new DailyRemittanceResource($remittance),
        ]);
    }

    /**
     * Everything done to the agreement, newest first: created, changed,
     * paused, resumed, days excused, terminated, swapped, completed, and the
     * car being handed over or taken back.
     */
    public function history(Request $request, FleetAgreement $fleetAgreement): JsonResponse
    {
        $vehicleEvents = ['fleet_vehicle_assigned', 'fleet_vehicle_unassigned'];

        $logs = AuditLog::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('auditable_type', $fleetAgreement->getMorphClass())->where('auditable_id', $fleetAgreement->id))
                ->orWhere(fn ($q) => $q->where('auditable_type', (new Vehicle)->getMorphClass())
                    ->where('auditable_id', $fleetAgreement->vehicle_id)
                    ->whereIn('event', $vehicleEvents)
                    ->where(fn ($q) => $q->where('new_values->agreement_id', $fleetAgreement->id)
                        ->orWhere('old_values->agreement_id', $fleetAgreement->id))))
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'history' => collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => self::HISTORY_DESCRIPTIONS[$log->event] ?? str_replace(['_', '.'], ' ', $log->event),
                'reason' => $log->new_values['reason'] ?? null,
                'changes' => $log->event === 'fleet_agreement.updated'
                    ? collect($log->new_values ?? [])->except('reason')->map(fn ($new, $field) => [
                        'from' => $log->old_values[$field] ?? null,
                        'to' => $new,
                    ])->all()
                    : null,
                'details' => collect($log->new_values ?? [])->except('reason')->all() ?: null,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => trim($log->actor->first_name.' '.$log->actor->last_name),
                    'role' => $log->actor->admin_role?->value,
                ] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
