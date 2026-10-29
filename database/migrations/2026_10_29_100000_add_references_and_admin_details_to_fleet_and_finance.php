<?php

use App\Models\Adjustment;
use App\Models\Payout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Readable references for the fleet and finance pages ("FA-000123",
     * "PO-000045", "AD-000007"), drawn from a sequence like SOS incidents.
     * Existing rows are numbered in the order they were created.
     *
     * @var array<string, string>
     */
    private const REFERENCES = [
        'fleet_agreements' => 'FA-',
        'payouts' => 'PO-',
        'adjustments' => 'AD-',
    ];

    public function up(): void
    {
        foreach (array_keys(self::REFERENCES) as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('reference_number')->nullable()->unique()->after('id');
            });
        }

        Schema::table('payouts', function (Blueprint $table) {
            $table->foreignUuid('rejected_by_admin_id')->nullable()->after('approved_by_admin_id')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
        });

        Schema::table('adjustments', function (Blueprint $table) {
            $table->foreignUuid('rejected_by_admin_id')->nullable()->after('approved_by_admin_id')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });

        Schema::table('fleet_agreements', function (Blueprint $table) {
            $table->text('paused_reason')->nullable()->after('paused_at');
        });

        // A fleet car taken off the road for servicing: it can't be given to a
        // driver until it is back.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('maintenance_started_at')->nullable()->after('block_reason');
            $table->string('maintenance_reason', 500)->nullable()->after('maintenance_started_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::REFERENCES as $table => $prefix) {
            DB::statement("
                UPDATE {$table} AS t
                SET reference_number = ? || LPAD(numbered.n::text, 6, '0')
                FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY created_at, id) AS n FROM {$table}) AS numbered
                WHERE t.id = numbered.id
            ", [$prefix]);

            $sequence = "{$table}_reference_number_seq";
            $last = (int) DB::table($table)->count();

            DB::statement("CREATE SEQUENCE IF NOT EXISTS {$sequence} OWNED BY {$table}.reference_number");
            DB::select('SELECT setval(?, ?, ?)', [$sequence, max($last, 1), $last > 0]);
        }

        // Who turned a payout or adjustment down was only in the audit log.
        foreach (['payouts' => (new Payout)->getMorphClass(), 'adjustments' => (new Adjustment)->getMorphClass()] as $table => $morphClass) {
            $event = $table === 'payouts' ? 'payout.rejected' : 'adjustment.rejected';
            $reasonColumn = $table === 'adjustments' ? ', rejection_reason = latest.reason' : '';

            DB::statement("
                UPDATE {$table} AS t
                SET rejected_by_admin_id = latest.actor_id, rejected_at = latest.created_at{$reasonColumn}
                FROM (
                    SELECT DISTINCT ON (auditable_id::text) auditable_id::text AS aid, actor_id, created_at, new_values->>'reason' AS reason
                    FROM audit_logs
                    WHERE auditable_type = ? AND event = ?
                    ORDER BY auditable_id::text, created_at DESC
                ) AS latest
                WHERE latest.aid = t.id::text AND t.status = 'rejected'
            ", [$morphClass, $event]);
        }
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['maintenance_started_at', 'maintenance_reason']);
        });

        Schema::table('fleet_agreements', function (Blueprint $table) {
            $table->dropColumn('paused_reason');
        });

        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by_admin_id');
            $table->dropColumn(['rejected_at', 'rejection_reason']);
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by_admin_id');
            $table->dropColumn('rejected_at');
        });

        foreach (array_keys(self::REFERENCES) as $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP SEQUENCE IF EXISTS {$table}_reference_number_seq");
            }

            Schema::table($table, function (Blueprint $table) {
                $table->dropUnique(['reference_number']);
                $table->dropColumn('reference_number');
            });
        }
    }
};
