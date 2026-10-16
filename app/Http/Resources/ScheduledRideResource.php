<?php

namespace App\Http\Resources;

use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Services\ScheduledRideCancellationFeeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin ScheduledRide
 */
class ScheduledRideResource extends JsonResource
{
    /**
     * @param  ScheduledRide  $resource
     * @param  Ride|null  $forRide  The booked ride, for its status, quote and fee preview
     */
    public function __construct($resource, private ?Ride $forRide = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = self::settings($request);
        $dispatchAt = $this->dispatchAt($settings['dispatch_lead_minutes']);
        $ride = $this->forRide;

        return [
            'scheduled_pickup_at' => $this->scheduled_pickup_at,
            // Matching starts here; the booking can be edited until then.
            'dispatch_at' => $dispatchAt,
            'can_edit' => $this->isUpcoming()
                && ($ride === null || $ride->status === RideStatus::Scheduled)
                && now()->lt($dispatchAt),
            'reminder_sent_at' => $this->reminder_sent_at,
            'dispatched_at' => $this->dispatched_at,
            'cancelled_at' => $this->cancelled_at,
            'dispatch_failure_reason' => $this->dispatch_failure_reason,
            'reservation_fee' => (float) data_get($ride?->pricing_snapshot, 'reservation_fee', 0),
            'reserved_driver' => $this->reserved_driver_id ? $this->reservedDriverPayload() : null,
            'reserved_at' => $this->reserved_at,
            'reserved_by_operations' => $this->reserved_by_admin_id !== null,
            'reservation_released_reason' => $this->reservation_released_reason,
            // What cancelling now would cost the passenger (0 when free).
            'cancellation_fee_if_cancelled_now' => $ride !== null && $this->cancelled_at === null
                ? ScheduledRideCancellationFeeService::feeIfCancelledNow($ride, $this->resource)
                : 0.0,
            'late_cancellation_fee' => $this->late_cancellation_fee !== null ? (float) $this->late_cancellation_fee : null,
            'late_cancellation_fee_status' => $this->late_cancellation_fee_status,
        ];
    }

    /**
     * Read once per request: a list of rides would otherwise query the
     * settings for every row. Not cached beyond the request, so a change by
     * operations shows at once.
     *
     * @return array<string, mixed>
     */
    private static function settings(Request $request): array
    {
        if (! $request->attributes->has('scheduled_ride_settings')) {
            $request->attributes->set('scheduled_ride_settings', AppSetting::scheduledRides());
        }

        return $request->attributes->get('scheduled_ride_settings');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reservedDriverPayload(): ?array
    {
        $driver = $this->reservedDriver;

        if ($driver === null) {
            return null;
        }

        $vehicle = $driver->driver?->vehicle;

        return [
            'id' => $driver->id,
            'first_name' => $driver->first_name,
            'profile_photo_url' => $driver->profile_photo_path
                ? Storage::disk(config('filesystems.uploads'))->url($driver->profile_photo_path)
                : null,
            'rating' => $driver->averageRating(),
            'vehicle' => $vehicle ? [
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'colour' => $vehicle->colour,
                'plate_number' => $vehicle->plate_number,
            ] : null,
        ];
    }
}
