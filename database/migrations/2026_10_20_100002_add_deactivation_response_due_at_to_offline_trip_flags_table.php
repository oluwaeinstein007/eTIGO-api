<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A driver whose flag reaches Level 3 gets time to dispute it before an
     * admin can uphold it or confirm the deactivation.
     */
    public function up(): void
    {
        Schema::table('offline_trip_flags', function (Blueprint $table) {
            $table->timestamp('deactivation_response_due_at')->nullable()->after('sanction_action');
        });

        // Level 3 flags already in the queue get the same window, counted
        // from when they were raised.
        DB::table('offline_trip_flags')
            ->where('sanction_tier', 3)
            ->whereNull('deactivation_response_due_at')
            ->update([
                'deactivation_response_due_at' => DB::raw("flagged_at + interval '".(int) config('offline_detection.deactivation_response_hours', 48)." hours'"),
            ]);
    }

    public function down(): void
    {
        Schema::table('offline_trip_flags', function (Blueprint $table) {
            $table->dropColumn('deactivation_response_due_at');
        });
    }
};
