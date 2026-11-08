<?php

namespace App\Jobs;

use App\Services\DriverDocumentComplianceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Daily (06:00 Lagos) driver document check: start or lift the expired
 * driving licence block, then send the renewal reminders due today (30, 14,
 * 7 and 1 days before expiry, on the day, and once expired), by push, in-app
 * and email. Blocks run first so an expired licence is reported once, as
 * the block.
 */
class ProcessDriverDocumentExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Update licence blocks, then send the reminders due today.
     */
    public function handle(DriverDocumentComplianceService $compliance): void
    {
        $compliance->refreshDue();
        $compliance->sendReminders();
        $compliance->remindMissingCityDocuments();
    }
}
