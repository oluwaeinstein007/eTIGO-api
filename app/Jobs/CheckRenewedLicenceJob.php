<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\DriverDocument;
use App\Services\DriverLicenceCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Looks a working driver's renewed licence up in FRSC records after upload,
 * off the request (the provider can take up to 30 seconds). A failed lookup
 * is stored as "unavailable", not retried, so a paid check never runs twice
 * for one upload; operations can run it again from the dashboard.
 */
class CheckRenewedLicenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * The renewed licence to look up.
     */
    public function __construct(public DriverDocument $document) {}

    /**
     * Skip papers already reviewed or replaced by the time the job runs.
     */
    public function handle(DriverLicenceCheckService $checks): void
    {
        $document = $this->document->fresh();

        if ($document === null || $document->status !== DocumentStatus::Pending) {
            return;
        }

        $checks->check($document);
    }
}
