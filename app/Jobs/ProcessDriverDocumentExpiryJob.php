<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\DriverDocument;
use App\Notifications\DriverDocumentExpiringNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily (06:00 Lagos) check of approved driver documents within 7 days of
 * expiry (or already expired). Each document gets one reminder email, ever,
 * tracked by `expiry_reminder_sent_at` — re-uploading a replacement document
 * creates a new row (see fleet onboarding docs flow), so this never blocks a
 * fresh reminder for a renewed document.
 */
class ProcessDriverDocumentExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        DriverDocument::query()
            ->where('status', DocumentStatus::Approved)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(7))
            ->whereNull('expiry_reminder_sent_at')
            ->with('driver.user')
            ->lazyById()
            ->each(function (DriverDocument $document) {
                try {
                    $driver = $document->driver;

                    if ($driver?->user?->email) {
                        $driver->user->notify(new DriverDocumentExpiringNotification($driver, $document));
                    }

                    $document->update(['expiry_reminder_sent_at' => now()]);
                } catch (\Throwable $e) {
                    Log::error('Driver document expiry reminder failed', [
                        'document_id' => $document->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
