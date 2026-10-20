<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record on the flag who confirmed a Level 3 deactivation and why (and
     * when an appeal reversed it), and who verified that the driver took cash
     * for the trip.
     */
    public function up(): void
    {
        Schema::table('offline_trip_flags', function (Blueprint $table) {
            $table->timestamp('deactivation_confirmed_at')->nullable()->after('review_notes');
            $table->foreignUuid('deactivation_confirmed_by_admin_id')->nullable()->after('deactivation_confirmed_at')->constrained('users')->nullOnDelete();
            $table->text('deactivation_notes')->nullable()->after('deactivation_confirmed_by_admin_id');
            $table->timestamp('deactivation_reversed_at')->nullable()->after('deactivation_notes');
            $table->timestamp('cash_taking_verified_at')->nullable()->after('deactivation_reversed_at');
            $table->foreignUuid('cash_taking_verified_by_admin_id')->nullable()->after('cash_taking_verified_at')->constrained('users')->nullOnDelete();
            $table->text('cash_taking_notes')->nullable()->after('cash_taking_verified_by_admin_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Deactivations confirmed before this change were only in the audit
        // log. auditable_id is not a uuid column everywhere, so compare as text.
        DB::statement(<<<'SQL'
            UPDATE offline_trip_flags AS f
            SET deactivation_confirmed_at = a.created_at,
                deactivation_confirmed_by_admin_id = a.actor_id::uuid,
                sanction_action = 'deactivated'
            FROM (
                SELECT DISTINCT ON (auditable_id) auditable_id, actor_id, created_at
                FROM audit_logs
                WHERE event = 'offline_flag_deactivation_confirmed'
                  AND auditable_type = 'App\Models\OfflineTripFlag'
                ORDER BY auditable_id, created_at
            ) AS a
            WHERE a.auditable_id::text = f.id::text
              AND f.deactivation_confirmed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('offline_trip_flags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivation_confirmed_by_admin_id');
            $table->dropConstrainedForeignId('cash_taking_verified_by_admin_id');
            $table->dropColumn([
                'deactivation_confirmed_at',
                'deactivation_notes',
                'deactivation_reversed_at',
                'cash_taking_verified_at',
                'cash_taking_notes',
            ]);
        });
    }
};
