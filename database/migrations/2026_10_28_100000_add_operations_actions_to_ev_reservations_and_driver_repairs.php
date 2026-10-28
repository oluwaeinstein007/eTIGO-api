<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations can now end a driver's charging reservation and handle repair
 * requests from the admin dashboard; these columns record who did what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ev_reservations', function (Blueprint $table) {
            $table->foreignUuid('ended_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 500)->nullable();
            // Why no fee was due: hire_purchase (still paying off an E-tiGo car) or tier.
            $table->string('fee_waiver_reason', 30)->nullable();
        });

        Schema::table('driver_repairs', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('completion_summary')->nullable();
            $table->foreignUuid('handled_by_admin_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('driver_repairs', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropConstrainedForeignId('handled_by_admin_id');
            $table->dropColumn(['scheduled_at', 'completed_at', 'cancelled_at', 'completion_summary']);
        });

        Schema::table('ev_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ended_by_admin_id');
            $table->dropColumn(['ended_at', 'end_reason', 'fee_waiver_reason']);
        });
    }
};
