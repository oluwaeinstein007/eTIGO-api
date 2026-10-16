<?php

namespace App\Jobs;

use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\ScheduledRide;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs every minute from the scheduler. Bookings live in the database, so a
 * restart or deploy loses nothing: whatever is due when the sweep next runs
 * is dispatched or reminded then. Each booking is handled by its own job so
 * one failure does not hold up the others.
 */
class ProcessScheduledRidesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $settings = AppSetting::scheduledRides();
        $dispatchHorizon = now()->addMinutes($settings['dispatch_lead_minutes']);

        $this->upcoming()
            ->where('scheduled_pickup_at', '<=', $dispatchHorizon)
            ->orderBy('scheduled_pickup_at')
            ->pluck('id')
            ->each(fn (string $id) => ScheduledRideDispatchJob::dispatch($id));

        if ($settings['reminder_minutes'] <= 0) {
            return;
        }

        $this->upcoming()
            ->where(fn ($query) => $query->whereNull('reminder_sent_at')
                ->orWhere(fn ($query) => $query->whereNotNull('reserved_driver_id')->whereNull('driver_reminder_sent_at')))
            ->where('scheduled_pickup_at', '<=', now()->addMinutes($settings['reminder_minutes']))
            ->where('scheduled_pickup_at', '>', $dispatchHorizon)
            ->pluck('id')
            ->each(fn (string $id) => ScheduledRideReminderJob::dispatch($id));
    }

    private function upcoming()
    {
        return ScheduledRide::query()
            ->upcoming()
            ->whereHas('ride', fn ($query) => $query->where('status', RideStatus::Scheduled));
    }
}
