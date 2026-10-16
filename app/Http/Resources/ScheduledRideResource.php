<?php

namespace App\Http\Resources;

use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\ScheduledRide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledRide
 */
class ScheduledRideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dispatchAt = $this->dispatchAt(AppSetting::scheduledRides()['dispatch_lead_minutes']);
        $rideStatus = $this->relationLoaded('ride') ? $this->ride?->status : null;

        return [
            'scheduled_pickup_at' => $this->scheduled_pickup_at,
            // Matching starts here; the booking can be edited until then.
            'dispatch_at' => $dispatchAt,
            'can_edit' => $this->isUpcoming()
                && ($rideStatus === null || $rideStatus === RideStatus::Scheduled)
                && now()->lt($dispatchAt),
            'reminder_sent_at' => $this->reminder_sent_at,
            'dispatched_at' => $this->dispatched_at,
            'cancelled_at' => $this->cancelled_at,
            'dispatch_failure_reason' => $this->dispatch_failure_reason,
        ];
    }
}
