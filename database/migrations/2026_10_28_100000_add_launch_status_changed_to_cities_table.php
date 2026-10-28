<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who last changed a city's launch status and when, so the admin city
 * screens can show "Closed by {admin} · {date}" without reading the audit
 * log for each city.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->timestamp('launch_status_changed_at')->nullable()->after('pause_scheduled_rides');
            $table->foreignUuid('launch_status_changed_by_admin_id')->nullable()->after('launch_status_changed_at')
                ->constrained('users')->nullOnDelete();
        });

        // From each city's latest status change in the audit log. The ids are
        // compared as text: auditable_id is a string column on some servers.
        DB::statement(<<<'SQL'
            UPDATE cities
            SET launch_status_changed_at = latest.created_at,
                launch_status_changed_by_admin_id = latest.actor_id
            FROM (
                SELECT DISTINCT ON (auditable_id::text) auditable_id::text AS city_id, created_at, actor_id
                FROM audit_logs
                WHERE auditable_type = ?
                  AND event IN ('city_launched', 'city_resumed', 'city_paused', 'city_returned_to_onboarding')
                ORDER BY auditable_id::text, created_at DESC, id DESC
            ) AS latest
            WHERE latest.city_id = cities.id::text
            SQL, ['App\\Models\\City']);
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('launch_status_changed_by_admin_id');
            $table->dropColumn('launch_status_changed_at');
        });
    }
};
