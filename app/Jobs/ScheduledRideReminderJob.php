<?php

namespace App\Jobs;

use App\Services\ScheduledRideService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the reminder pushes for one scheduled ride (BE2-SCH-05): to the
 * passenger and, when a driver reserved it, to that driver. Queued by
 * ProcessScheduledRidesJob; each reminder goes out at most once.
 */
class ScheduledRideReminderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $scheduledRideId,
    ) {}

    public function uniqueId(): string
    {
        return $this->scheduledRideId;
    }

    public function handle(ScheduledRideService $service): void
    {
        $service->sendReminder($this->scheduledRideId);
        $service->sendDriverReminder($this->scheduledRideId);
    }
}
