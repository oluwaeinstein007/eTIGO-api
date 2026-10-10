<?php

namespace App\Services;

use App\Models\CommissionConfig;
use App\Models\Driver;

class CommissionService
{
    public function getRate(string $driverId): float
    {
        $driver = Driver::find($driverId);

        if ($driver?->isFleetVehicle() && $driver->activeFleetAgreement()->exists()) {
            return 0.0;
        }

        $override = CommissionConfig::active()
            ->forDriver($driverId)
            ->first();

        if ($override) {
            return (float) $override->rate;
        }

        $global = CommissionConfig::active()
            ->global()
            ->first();

        if ($global) {
            return (float) $global->rate;
        }

        return (float) config('wallet.default_commission_rate', 0.2000);
    }

    /**
     * Calculate commission and net earnings from a fare amount (in kobo).
     *
     * @return array{commission: int, net_earnings: int, rate: float}
     */
    public function calculate(int $fareAmount, string $driverId): array
    {
        $rate = $this->getRate($driverId);
        $commission = (int) round($fareAmount * $rate);
        $netEarnings = $fareAmount - $commission;

        return [
            'commission' => $commission,
            'net_earnings' => $netEarnings,
            'rate' => $rate,
        ];
    }
}
