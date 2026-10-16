<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operations view of scheduled rides (Phase 2): the upcoming bookings and the
 * scheduling settings (BE2-SCH-09).
 */
class AdminScheduledRideController extends Controller
{
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
        ]);

        $rides = Ride::query()
            ->where('rides.status', RideStatus::Scheduled)
            ->join('scheduled_rides', 'scheduled_rides.ride_id', '=', 'rides.id')
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('rides.city_id', $v))
            ->when($request->input('vehicle_class_id'), fn ($q, $v) => $q->where('rides.vehicle_class_id', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->where('scheduled_rides.scheduled_pickup_at', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->where('scheduled_rides.scheduled_pickup_at', '<=', $v))
            ->orderBy('scheduled_rides.scheduled_pickup_at')
            ->select('rides.*')
            ->with(['city', 'vehicleClass', 'passenger', 'promoCode', 'scheduledRide'])
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
