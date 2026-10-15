<?php

namespace App\Jobs;

use App\Services\ReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Frees stalls held by drivers who never arrived within
 * ev_charging.reservation_expiry_minutes and passes them down the queue.
 */
class ExpireEvReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ReservationService $reservationService): void
    {
        $expired = $reservationService->expireOverdueReservations();

        if ($expired > 0) {
            Log::info("Expired {$expired} overdue EV reservations.");
        }
    }
}
