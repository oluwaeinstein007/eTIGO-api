<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reference numbers are drawn from a database sequence so records created
     * at the same moment (two bookings, two sign-ups) never receive the same
     * number. Each sequence continues after the highest number already issued.
     *
     * @var array<string, array{prefix: string, floor: int}>
     */
    private const SEQUENCES = [
        'rides' => ['prefix' => 'TRP-NG-', 'floor' => 0],
        'users' => ['prefix' => 'P-', 'floor' => 0],
        'drivers' => ['prefix' => 'D-', 'floor' => 0],
        'disputes' => ['prefix' => 'DPT-', 'floor' => 0],
        'driver_repairs' => ['prefix' => 'REP-', 'floor' => 1040],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::SEQUENCES as $table => ['prefix' => $prefix, 'floor' => $floor]) {
            $sequence = "{$table}_reference_number_seq";

            // The offset is inlined: a bound (untyped) parameter would make
            // Postgres pick the regex form of SUBSTRING.
            $offset = strlen($prefix) + 1;
            $highest = (int) DB::table($table)
                ->whereRaw('reference_number ~ ?', ["^{$prefix}[0-9]+$"])
                ->selectRaw("MAX(CAST(SUBSTRING(reference_number FROM {$offset}) AS BIGINT)) AS highest")
                ->value('highest');
            $last = max($highest, $floor);

            DB::statement("CREATE SEQUENCE IF NOT EXISTS {$sequence} OWNED BY {$table}.reference_number");
            DB::select('SELECT setval(?, ?, ?)', [$sequence, max($last, 1), $last > 0]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::SEQUENCES) as $table) {
            DB::statement("DROP SEQUENCE IF EXISTS {$table}_reference_number_seq");
        }
    }
};
