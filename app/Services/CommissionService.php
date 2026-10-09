<?php

namespace App\Services;

use App\Models\CommissionConfig;

class CommissionService
{
    /**
     * Get the effective commission rate for a driver.
     * Returns per-driver override if active, else the global default.
     */
    public function getRate(string $driverId): float
    {
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
