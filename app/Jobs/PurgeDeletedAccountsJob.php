<?php

namespace App\Jobs;

use App\Services\AccountDeletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Erases passenger and driver accounts whose deletion grace period has
 * ended without the user logging back in.
 */
class PurgeDeletedAccountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AccountDeletionService $accountDeletion): void
    {
        $purged = $accountDeletion->purgeDue();

        if ($purged > 0) {
            Log::info("Erased {$purged} accounts after their deletion grace period.");
        }
    }
}
