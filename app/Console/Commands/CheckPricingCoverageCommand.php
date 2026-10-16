<?php

namespace App\Console\Commands;

use App\Services\PricingCoverageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Lists vehicle classes that are offered in a city but have no pricing in
 * effect. Fare estimates leave those classes out, and a city where none is
 * priced returns no estimates at all. Runs daily and logs a warning for any
 * gap in an active city.
 */
class CheckPricingCoverageCommand extends Command
{
    protected $signature = 'pricing:check-coverage {--city= : Only check this city (ID)}';

    protected $description = 'List vehicle classes offered in a city that have no pricing in effect';

    public function handle(PricingCoverageService $coverage): int
    {
        $gaps = $coverage->gaps(array_filter([$this->option('city')]));

        if ($gaps->isEmpty()) {
            $this->info('Every vehicle class offered in a city has pricing in effect.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d city/vehicle class pair(s) have no pricing in effect:', $gaps->count()));
        $this->table(
            ['City', 'City active', 'Vehicle class', 'Next pricing starts'],
            $gaps->map(fn (array $gap) => [
                $gap['city_name'],
                $gap['city_is_active'] ? 'yes' : 'no',
                $gap['vehicle_class_name'],
                $gap['next_effective_from'] ?? 'none scheduled',
            ])->all(),
        );
        $this->line('Add pricing under /admin/pricing, or turn the class off for that city.');

        $active = $gaps->where('city_is_active', true);

        if ($active->isNotEmpty()) {
            Log::warning('Vehicle classes offered in active cities have no pricing in effect.', [
                'gaps' => $active->map(fn (array $gap) => "{$gap['city_name']} / {$gap['vehicle_class_name']}")->values()->all(),
            ]);
        }

        return self::FAILURE;
    }
}
