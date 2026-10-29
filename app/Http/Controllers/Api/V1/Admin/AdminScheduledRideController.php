<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use App\Services\ScheduledRideReservationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operations view of scheduled rides (Phase 2): the upcoming bookings, driver
 * pre-assignment and the scheduling settings (BE2-SCH-09).
 */
class AdminScheduledRideController extends Controller
{
    public function __construct(
        private ScheduledRideReservationService $reservationService,
    ) {}

    /**
     * Bookings in `scheduled` status, soonest pickup first.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'city_id' => ['sometimes', 'nullable', 'uuid'],
            'vehicle_class_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer'],
            'reserved' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $rides = Ride::query()
            ->where('rides.status', RideStatus::Scheduled)
            ->join('scheduled_rides', 'scheduled_rides.ride_id', '=', 'rides.id')
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('rides.city_id', $v))
            ->when($request->input('vehicle_class_id'), fn ($q, $v) => $q->where('rides.vehicle_class_id', $v))
            // Pickup times are stored in UTC; an offset in the filter must count.
            ->when($request->input('from'), fn ($q, $v) => $q->where('scheduled_rides.scheduled_pickup_at', '>=', Carbon::parse($v)->utc()))
            ->when($request->input('to'), fn ($q, $v) => $q->where('scheduled_rides.scheduled_pickup_at', '<=', Carbon::parse($v)->utc()))
            ->when($request->filled('search'), fn ($q) => $this->search($q, trim($request->string('search')->value())))
            ->orderBy('scheduled_rides.scheduled_pickup_at')
            // Bookings at the same minute must not swap between pages.
            ->orderBy('rides.id')
            ->select('rides.*')
            ->when($request->has('reserved'), fn ($q) => $request->boolean('reserved')
                ? $q->whereNotNull('scheduled_rides.reserved_driver_id')
                : $q->whereNull('scheduled_rides.reserved_driver_id'))
            ->with(['city', 'vehicleClass', 'passenger', 'promoCode', 'scheduledRide.reservedDriver.driver.vehicle'])
            ->paginate($this->perPage($request, 25, 100));

        return response()->json([
            'rides' => RideResource::collection($rides),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
        ]);
    }

    /**
     * Match the trip reference, the addresses, or the name or phone of the
     * passenger (the booker), the rider of a booking made for someone else
     * or the reserved driver. Phones are stored as +234…; a local number
     * (0803 123 4567) or part of one matches too.
     */
    private function search(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $phone = $this->phoneDigits($term);

        $person = fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->whereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", [$like])
            ->when($phone, fn (Builder $q) => $q->orWhere('phone', 'like', "%{$phone}%")));

        $query->where(fn (Builder $q) => $q
            ->where('rides.reference_number', 'ilike', $like)
            ->orWhere('rides.pickup_address', 'ilike', $like)
            ->orWhere('rides.destination_address', 'ilike', $like)
            ->orWhere('rides.rider_name', 'ilike', $like)
            ->when($phone, fn (Builder $q) => $q->orWhere('rides.rider_phone', 'like', "%{$phone}%"))
            ->orWhereHas('passenger', $person)
            ->orWhereHas('scheduledRide.reservedDriver', $person));
    }

    /**
     * The digits to look for in a stored phone, or null when the term is not
     * a phone number. A leading 0 (local format) is dropped so it matches the
     * +234 form.
     */
    private function phoneDigits(string $term): ?string
    {
        if (preg_match('/^\+?[\d\s\-().]+$/', $term) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $term);

        if (strlen($digits) < 4) {
            return null;
        }

        return str_starts_with($digits, '0') ? substr($digits, 1) : $digits;
    }

    /**
     * Assign a driver to a booking ahead of dispatch (or move it to another
     * driver). At dispatch the ride goes straight to them if they are free.
     */
    public function assign(Request $request, Ride $ride): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $driver = User::where('type', UserType::Driver)->find($validated['driver_id']);

        if ($driver === null) {
            throw ValidationException::withMessages(['driver_id' => ['The selected user is not a driver.']]);
        }

        $ride->load('scheduledRide');
        $ride = $this->reservationService->reserve($ride, $driver, $request->user());

        return response()->json([
            'message' => 'Driver assigned to the scheduled ride.',
            'ride' => new RideResource($ride->load(['city', 'vehicleClass', 'passenger', 'scheduledRide.reservedDriver.driver.vehicle'])),
        ]);
    }

    public function unassign(Request $request, Ride $ride): JsonResponse
    {
        $ride = $this->reservationService->release($ride, $request->user(), byAdmin: true);

        return response()->json([
            'message' => 'Driver removed from the scheduled ride.',
            'ride' => new RideResource($ride->load(['city', 'vehicleClass', 'passenger', 'scheduledRide'])),
        ]);
    }

    public function showSettings(): JsonResponse
    {
        return response()->json([
            'settings' => AppSetting::scheduledRides(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'min_lead_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'max_advance_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'dispatch_lead_minutes' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'reminder_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'matching_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'retry_wave_delay_seconds' => ['sometimes', 'integer', 'min:10', 'max:300'],
            'max_upcoming_per_passenger' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'min_minutes_between_bookings' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'driver_reservations_enabled' => ['sometimes', 'boolean'],
            'max_reservations_per_driver' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'driver_reservation_gap_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'driver_reservation_buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'reservation_fee' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'late_cancellation_fee_basis' => ['sometimes', 'string', 'in:economy_base_fare,flat,off'],
            'late_cancellation_fee_vehicle_class' => ['sometimes', 'string', 'exists:vehicle_classes,name'],
            'late_cancellation_fee' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'late_cancellation_window_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
        ]);

        $current = AppSetting::scheduledRides();
        $next = array_merge($current, $validated);

        // A new booking must not already be due for dispatch, and a reminder
        // has to arrive before matching starts to be of any use.
        if ($next['dispatch_lead_minutes'] > $next['min_lead_minutes']) {
            throw ValidationException::withMessages([
                'dispatch_lead_minutes' => ['dispatch_lead_minutes cannot be more than min_lead_minutes.'],
            ]);
        }

        if ($next['reminder_minutes'] > 0 && $next['reminder_minutes'] <= $next['dispatch_lead_minutes']) {
            throw ValidationException::withMessages([
                'reminder_minutes' => ['reminder_minutes must be more than dispatch_lead_minutes (or 0 to turn reminders off).'],
            ]);
        }

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record(
                $request->user(),
                'scheduled_ride_settings.updated',
                $request->user(),
                array_intersect_key($current, $validated),
                $validated,
            );

            foreach ($validated as $key => $value) {
                AppSetting::setValue(
                    "scheduled_rides.{$key}",
                    is_bool($value) ? ($value ? 'true' : 'false') : $value,
                    'scheduled_rides',
                );
            }
        });

        return response()->json([
            'message' => 'Scheduled ride settings updated.',
            'settings' => AppSetting::scheduledRides(),
        ]);
    }
}
