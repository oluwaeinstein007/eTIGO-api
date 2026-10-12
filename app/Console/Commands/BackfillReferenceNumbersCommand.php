<?php

namespace App\Console\Commands;

use App\Models\Dispute;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Console\Command;

class BackfillReferenceNumbersCommand extends Command
{
    protected $signature = 'reference-numbers:backfill
                            {--model= : Specific model to backfill (driver, passenger, dispute, ride)}
                            {--chunk=500 : Number of records to process per chunk}';

    protected $description = 'Backfill reference numbers for existing records that do not have one';

    public function handle(): int
    {
        $models = $this->getModels();

        foreach ($models as $label => $class) {
            $this->backfill($label, $class);
        }

        $this->newLine();
        $this->info('Backfill complete.');

        return self::SUCCESS;
    }

    private function getModels(): array
    {
        $all = [
            'Driver' => Driver::class,
            'Passenger' => User::class,
            'Dispute' => Dispute::class,
            'Ride' => Ride::class,
        ];

        $filter = $this->option('model');

        if ($filter) {
            $key = ucfirst(strtolower($filter));
            if (! isset($all[$key])) {
                $this->error("Unknown model: {$filter}. Options: driver, passenger, dispute, ride");

                return [];
            }

            return [$key => $all[$key]];
        }

        return $all;
    }

    private function backfill(string $label, string $class): void
    {
        $query = $class::whereNull('reference_number');

        if ($class === User::class) {
            $query->where('type', 'passenger');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info("{$label}: No records need backfill.");

            return;
        }

        $this->info("{$label}: Backfilling {$total} records...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $chunk = (int) $this->option('chunk');
        $filled = 0;

        $class::whereNull('reference_number')
            ->when($class === User::class, fn ($q) => $q->where('type', 'passenger'))
            ->orderBy('created_at')
            ->chunk($chunk, function ($records) use (&$filled, $bar, $class) {
                foreach ($records as $record) {
                    $record->reference_number = $class::generateReferenceNumber();
                    $record->saveQuietly();
                    $filled++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("{$label}: {$filled} records backfilled.");
    }
}
