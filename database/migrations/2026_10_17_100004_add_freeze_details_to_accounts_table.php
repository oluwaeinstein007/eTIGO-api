<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why and by whom a wallet is frozen, kept on the account while it is
     * frozen; the audit log keeps the full history.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable()->after('status');
            $table->text('frozen_reason')->nullable()->after('frozen_at');
            $table->foreignUuid('frozen_by_admin_id')->nullable()->after('frozen_reason')
                ->constrained('users')->nullOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            UPDATE accounts AS acc
            SET frozen_at = latest.created_at,
                frozen_reason = latest.new_values->>'reason',
                frozen_by_admin_id = latest.actor_id
            FROM (
                SELECT DISTINCT ON (auditable_id) auditable_id, created_at, new_values, actor_id
                FROM audit_logs
                WHERE event = 'wallet.frozen'
                ORDER BY auditable_id, created_at DESC
            ) AS latest
            WHERE acc.id::text = latest.auditable_id::text AND acc.status = 'frozen'
        ");
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('frozen_by_admin_id');
            $table->dropColumn(['frozen_at', 'frozen_reason']);
        });
    }
};
