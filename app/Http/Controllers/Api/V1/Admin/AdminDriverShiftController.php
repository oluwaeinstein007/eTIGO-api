<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverShiftResource;
use App\Http\Resources\ShiftEligibleDriverResource;
use App\Http\Resources\VehicleResource;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverShiftService;
use App\Support\LagosDay;
use App\Support\SearchTerm;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Operations run the timetable of shared fleet cars (OQ-20): turn shift
 * mode on per car, assign shifts (or a daily rota), move, shorten, extend
 * and cancel them.
 */
class AdminDriverShiftController extends Controller
{
    public function __construct(
        private DriverShiftService $shifts,
    ) {}

    /**
     * Shifts overlapping a time range, soonest first. `summary` counts every
     * status under the same filters except `status`, for the tab counts.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'driver_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                $allowed = array_column(DriverShiftStatus::cases(), 'value');

                if (array_diff($this->statusList($value), $allowed) !== []) {
                    $fail('The status must be one or more of: '.implode(', ', $allowed).', separated by commas.');
                }
            }],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'nullable', 'integer'],
        ]);

        [$from, $to] = $this->range($validated['from'] ?? null, $validated['to'] ?? null);
        $statuses = $this->statusList($validated['status'] ?? '');
        $search = trim((string) ($validated['search'] ?? ''));

        $filtered = fn () => DriverShift::query()
            ->overlapping($from, $to)
            ->when($validated['vehicle_id'] ?? null, fn (Builder $q, string $id) => $q->where('vehicle_id', $id))
            ->when($validated['driver_id'] ?? null, fn (Builder $q, string $id) => $q->where('driver_id', $id))
            ->when($search !== '', fn (Builder $q) => $this->search($q, $search));

        $shifts = $filtered()
            ->with(['driver.user', 'vehicle'])
            ->when($statuses !== [], fn (Builder $q) => $q->whereIn('status', $statuses))
            ->orderBy('starts_at')
            // Shifts starting together must not swap between pages.
            ->orderBy('id')
            ->paginate($this->perPage($request));

        $counts = $filtered()->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'shifts' => DriverShiftResource::collection($shifts),
            'summary' => collect(DriverShiftStatus::cases())
                ->mapWithKeys(fn (DriverShiftStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)]),
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
    }

    /**
     * Drivers a shift can be given to (Driver::scopeShiftEligible), by name.
     * With `starts_at` and `ends_at`, each driver carries the shift of theirs
     * that clashes with that time, if any, and free drivers come first.
     */
    public function eligibleDrivers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city_id' => ['sometimes', 'nullable', 'uuid'],
            // Both or neither.
            'starts_at' => ['required_with:ends_at', 'nullable', 'date'],
            'ends_at' => ['required_with:starts_at', 'nullable', 'date', 'after:starts_at'],
            // The shift being edited, so its own driver is not a clash.
            'except_shift_id' => ['sometimes', 'nullable', 'uuid'],
            'per_page' => ['sometimes', 'nullable', 'integer'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $window = isset($validated['starts_at'], $validated['ends_at'])
            ? [CarbonImmutable::parse($validated['starts_at'])->utc(), CarbonImmutable::parse($validated['ends_at'])->utc()]
            : null;

        // Called with a query (withExists) and with the relation (with).
        $clashing = fn ($q) => $q->holdingSlot()
            ->overlapping(...$window)
            ->when($validated['except_shift_id'] ?? null, fn (Builder $q, string $id) => $q->whereKeyNot($id));

        $drivers = Driver::query()
            ->shiftEligible()
            ->with(['user', 'city', 'vehicle'])
            ->withCount(['shifts as upcoming_shifts_count' => fn (Builder $q) => $q->workable()->where('ends_at', '>', now())])
            ->when($validated['city_id'] ?? null, fn (Builder $q, string $id) => $q->where('city_id', $id))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $this->searchDriver($q, $search)))
            ->when($window !== null, fn (Builder $q) => $q
                ->with(['shifts' => fn ($q) => $clashing($q)->with('vehicle')->orderBy('starts_at')])
                ->withExists(['shifts as has_clash' => $clashing])
                ->orderBy('has_clash'))
            ->orderBy(User::select('first_name')->whereColumn('users.id', 'drivers.user_id'))
            ->orderBy(User::select('last_name')->whereColumn('users.id', 'drivers.user_id'))
            ->orderBy('drivers.id')
            ->paginate($this->perPage($request));

        return response()->json([
            'drivers' => ShiftEligibleDriverResource::collection($drivers),
            'meta' => [
                'current_page' => $drivers->currentPage(),
                'last_page' => $drivers->lastPage(),
                'per_page' => $drivers->perPage(),
                'total' => $drivers->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'uuid', Rule::exists('drivers', 'id')],
            'vehicle_id' => ['required', 'uuid', Rule::exists('vehicles', 'id')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:500'],
            // The same shift on each of the next N days (a rota).
            'repeat_daily_for' => ['sometimes', 'integer', 'min:0', 'max:13'],
        ]);

        $shifts = $this->shifts->create($request->user(), $validated);

        return response()->json([
            'message' => $shifts->count() === 1 ? 'Shift created.' : "{$shifts->count()} shifts created.",
            'shifts' => DriverShiftResource::collection($shifts->each->load(['driver.user', 'vehicle'])),
        ], 201);
    }

    public function show(DriverShift $shift): JsonResponse
    {
        return response()->json([
            'shift' => new DriverShiftResource($shift->load(['driver.user', 'vehicle'])),
        ]);
    }

    public function update(Request $request, DriverShift $shift): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['sometimes', 'uuid', Rule::exists('drivers', 'id')],
            'vehicle_id' => ['sometimes', 'uuid', Rule::exists('vehicles', 'id')],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'Nothing to change.'], 422);
        }

        $shift = $this->shifts->update($shift, $request->user(), $validated);

        return response()->json([
            'message' => 'Shift updated.',
            'shift' => new DriverShiftResource($shift->load(['driver.user', 'vehicle'])),
        ]);
    }

    public function cancel(Request $request, DriverShift $shift): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $shift = $this->shifts->cancel($shift, $request->user(), $validated['reason']);

        return response()->json([
            'message' => 'Shift cancelled.',
            'shift' => new DriverShiftResource($shift->load(['driver.user', 'vehicle'])),
        ]);
    }

    public function shiftMode(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'shift_mode' => ['required', 'boolean'],
        ]);

        $vehicle = $this->shifts->setShiftMode($vehicle, (bool) $validated['shift_mode'], $request->user());

        return response()->json([
            'message' => $vehicle->shift_mode ? 'Shift mode is on: drivers share this car on shifts.' : 'Shift mode is off.',
            'vehicle' => new VehicleResource($vehicle->load(['driver.user', 'vehicleClass'])),
        ]);
    }

    /**
     * Match the shift reference, the driver (reference, name or phone) or
     * the car's plate, typed with or without spaces and dashes.
     */
    private function search(Builder $query, string $term): void
    {
        $like = SearchTerm::like($term);
        $plate = SearchTerm::plateKey($term);

        $query->where(fn (Builder $q) => $q
            ->where('driver_shifts.reference_number', 'ilike', $like)
            ->orWhereHas('driver', fn (Builder $q) => $this->searchDriver($q, $term))
            ->orWhereHas('vehicle', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('plate_number', 'ilike', $like)
                ->when($plate, fn (Builder $q) => $q->orWhereRaw(SearchTerm::plateColumn('plate_number').' LIKE ?', ["%{$plate}%"])))));
    }

    /**
     * Match a driver by reference, full name or phone (local or +234 form).
     */
    private function searchDriver(Builder $query, string $term): void
    {
        $like = SearchTerm::like($term);
        $phone = SearchTerm::phoneDigits($term);

        $query->where(fn (Builder $q) => $q
            ->where('drivers.reference_number', 'ilike', $like)
            ->orWhereHas('user', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", [$like])
                ->when($phone, fn (Builder $q) => $q->orWhere('phone', 'like', "%{$phone}%")))));
    }

    /**
     * @return list<string>
     */
    private function statusList(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $s) => $s !== ''));
    }

    /**
     * The range to list. A plain date (Y-m-d) is a Lagos day: `from` its
     * start, `to` its end. Without `from` the range starts 15 days before
     * `to`; without `to` it ends 15 days after `from`; without either it runs
     * from yesterday to 14 days ahead.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws ValidationException
     */
    private function range(?string $from, ?string $to): array
    {
        $isDay = fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        $start = $from === null ? null : ($isDay($from) ? LagosDay::start($from) : CarbonImmutable::parse($from)->utc());
        $end = $to === null ? null : ($isDay($to) ? LagosDay::end($to) : CarbonImmutable::parse($to)->utc());

        $start ??= $end?->subDays(15) ?? CarbonImmutable::now()->subDay();
        $end ??= $start->addDays(15);

        if ($end->lte($start)) {
            throw ValidationException::withMessages(['to' => ['The end of the range must be after its start.']]);
        }

        return [$start, $end];
    }
}
