<?php

namespace App\Jobs;

use App\Enums\HoldStatus;
use App\Enums\RideStatus;
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
        // A hold backs an in-flight ride; a long trip must keep its hold so
        // the fare can still be settled when the ride completes.
        $expired = Hold::where('status', HoldStatus::Active)
            ->where('expires_at', '<', now())
            ->where(function ($query) {
                $query->whereNull('ride_id')
                    ->orWhereDoesntHave('ride', fn ($ride) => $ride->whereIn('status', RideStatus::activeStatuses()));
            })
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
