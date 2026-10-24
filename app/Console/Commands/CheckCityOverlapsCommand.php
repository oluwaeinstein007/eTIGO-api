<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Services\CityBoundaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Lists cities whose boundaries overlap. A pickup in an overlap belongs to
 * the smaller city, so a district drawn as its own city inside a larger
 * one takes that area's bookings away from it. Saving refuses new
 * overlaps; this finds the ones saved before that check. Runs daily and
 * logs a warning for any overlap that involves a live city.
 */
class CheckCityOverlapsCommand extends Command
{
    protected $signature = 'cities:check-overlaps';

    protected $description = 'List cities whose map boundaries overlap';

    public function handle(CityBoundaryService $boundaries): int
    {
        $pairs = $boundaries->overlappingPairs();

        if ($pairs === []) {
            $this->info('No city boundaries overlap.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d pair(s) of cities have overlapping boundaries:', count($pairs)));
        $this->table(
            ['City', 'Status', 'Overlaps', 'Status'],
            array_map(fn (array $pair) => [
                $pair[0]->name,
                $pair[0]->launch_status->value,
                $pair[1]->name,
                $pair[1]->launch_status->value,
            ], $pairs),
        );
        $this->line('Redraw one boundary under /admin/cities so each pickup maps to one city, or clear the boundary of a city that should not take bookings.');

        $live = array_filter($pairs, fn (array $pair) => $pair[0]->isLive() || $pair[1]->isLive());

        if ($live !== []) {
            Log::warning('Live city boundaries overlap: pickups in the overlap go to the smaller city.', [
                'pairs' => array_values(array_map(fn (array $pair) => implode(' / ', array_map(fn (City $city) => $city->name, $pair)), $live)),
            ]);
        }

        return self::FAILURE;
    }
}
