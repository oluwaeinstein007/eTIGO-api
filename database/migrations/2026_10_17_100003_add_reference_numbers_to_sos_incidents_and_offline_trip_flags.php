<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Readable references for the safety and compliance queues ("SOS-000123",
     * "FLG-000045"), drawn from a sequence like rides and disputes. Existing
     * rows are numbered in the order they were created.
     *
     * @var array<string, array{prefix: string, order_by: string}>
     */
    private const TABLES = [
        'sos_incidents' => ['prefix' => 'SOS-', 'order_by' => 'created_at'],
        'offline_trip_flags' => ['prefix' => 'FLG-', 'order_by' => 'flagged_at'],
    ];

    public function up(): void
    {
        Schema::table('sos_incidents', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->unique()->after('id');
            // When an operator first took the alert: the end of its wait in the queue.
            $table->timestamp('operator_assigned_at')->nullable()->after('operator_id');
        });

        Schema::table('offline_trip_flags', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->unique()->after('id');
            $table->timestamp('disputed_at')->nullable()->after('dispute_notes');
            $table->string('dispute_channel')->nullable()->after('disputed_at');
            $table->text('review_notes')->nullable()->after('dispute_outcome');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table => ['prefix' => $prefix, 'order_by' => $orderBy]) {
            DB::statement("
                UPDATE {$table} AS t
                SET reference_number = ? || LPAD(numbered.n::text, 6, '0')
                FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY {$orderBy}, id) AS n FROM {$table}) AS numbered
                WHERE t.id = numbered.id
            ", [$prefix]);

            $sequence = "{$table}_reference_number_seq";
            $last = (int) DB::table($table)->count();

            DB::statement("CREATE SEQUENCE IF NOT EXISTS {$sequence} OWNED BY {$table}.reference_number");
            DB::select('SELECT setval(?, ?, ?)', [$sequence, max($last, 1), $last > 0]);
        }

        DB::statement("
            UPDATE sos_incidents AS i
            SET operator_assigned_at = first_take.at
            FROM (
                SELECT incident_id, MIN(created_at) AS at
                FROM sos_event_log
                WHERE event_type LIKE 'transition:%->operator_assigned'
                GROUP BY incident_id
            ) AS first_take
            WHERE i.id = first_take.incident_id
        ");

        // Disputes were only ever filed from the driver app; the dispute time
        // is the audit record of it.
        DB::statement("
            UPDATE offline_trip_flags AS f
            SET dispute_channel = 'driver_app',
                disputed_at = COALESCE(
                    (SELECT MIN(a.created_at) FROM audit_logs AS a
                     WHERE a.event = 'offline_flag_disputed' AND a.auditable_id = f.id),
                    f.updated_at
                )
            WHERE f.is_disputed = true
        ");

        DB::statement("
            UPDATE offline_trip_flags AS f
            SET review_notes = (
                SELECT a.new_values->>'resolution_notes' FROM audit_logs AS a
                WHERE a.event = 'offline_flag_reviewed' AND a.auditable_id = f.id
                ORDER BY a.created_at DESC
                LIMIT 1
            )
            WHERE f.resolved_at IS NOT NULL
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_keys(self::TABLES) as $table) {
                DB::statement("DROP SEQUENCE IF EXISTS {$table}_reference_number_seq");
            }
        }

        Schema::table('offline_trip_flags', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
            $table->dropColumn(['reference_number', 'disputed_at', 'dispute_channel', 'review_notes']);
        });

        Schema::table('sos_incidents', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
            $table->dropColumn(['reference_number', 'operator_assigned_at']);
        });
    }
};
