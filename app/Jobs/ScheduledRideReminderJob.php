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
 * Sends the reminder push for one scheduled ride (BE2-SCH-05). Queued by
 * ProcessScheduledRidesJob; the reminder goes out at most once.
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
    }
}
