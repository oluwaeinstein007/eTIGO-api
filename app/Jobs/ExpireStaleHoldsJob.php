<?php

namespace App\Jobs;

use App\Enums\HoldStatus;
use App\Models\Hold;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireStaleHoldsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $expired = Hold::where('status', HoldStatus::Active)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $hold) {
            DB::transaction(function () use ($hold) {
                $hold = Hold::whereKey($hold->id)->lockForUpdate()->first();

                if ($hold && $hold->status === HoldStatus::Active) {
                    $hold->update([
                        'status' => HoldStatus::Expired,
                        'released_at' => now(),
                    ]);
                }
            });
        }

        Log::info("Expired {$expired->count()} stale holds.");
    }
}
