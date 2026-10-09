<?php

namespace App\Jobs;

use App\Models\DailyRemittance;
use App\Models\FleetAgreement;
use App\Services\FleetRemittanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DailyRemittanceSettlementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private string $date,
    ) {}

    public function handle(FleetRemittanceService $service): void
    {
        FleetAgreement::active()->each(function (FleetAgreement $agreement) {
            DailyRemittance::firstOrCreate(
                [
                    'agreement_id' => $agreement->id,
                    'date' => $this->date,
                ],
                [
                    'driver_id' => $agreement->driver_id,
                    'target_amount' => $agreement->daily_remittance_target,
                ],
            );
        });

        $unsettled = DailyRemittance::unsettled()
            ->forDate($this->date)
            ->with('agreement')
            ->get();

        Log::info('Settling daily remittances', [
            'date' => $this->date,
            'count' => $unsettled->count(),
        ]);

        foreach ($unsettled as $remittance) {
            $service->settleDay($remittance);
        }
    }
}
