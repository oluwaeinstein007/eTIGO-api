<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations review of driver charge requests: the confirmed slot as a real
 * time (confirmed_slot and check_in_time stay as the display strings the
 * driver app already reads), who reviewed it and why it was declined.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ev_reservations', function (Blueprint $table) {
            $table->timestamp('confirmed_slot_at')->nullable();
            $table->foreignUuid('reviewed_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decline_reason', 500)->nullable();

            $table->index(['status', 'confirmed_slot_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ev_reservations', function (Blueprint $table) {
            $table->dropIndex(['status', 'confirmed_slot_at']);
            $table->dropConstrainedForeignId('reviewed_by_admin_id');
            $table->dropColumn(['confirmed_slot_at', 'reviewed_at', 'decline_reason']);
        });
    }
};
