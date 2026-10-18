<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 multi-city (OQ-22): cities move through onboarding / live /
 * paused instead of an on/off switch, carry the readiness checklist's
 * manual confirmations, and passengers can join a city's waitlist.
 * is_active stays, kept equal to "live" by the City model, for the
 * clients and queries that read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('launch_status')->default('onboarding')->after('is_active');
            // The target date the client gave for the launch.
            $table->date('launch_date')->nullable()->after('launch_status');
            // First time the city went live.
            $table->timestamp('launched_at')->nullable()->after('launch_date');
            $table->timestamp('paused_at')->nullable()->after('launched_at');
            $table->string('pause_reason', 500)->nullable()->after('paused_at');
            // What the pause does to upcoming bookings: honour or cancel.
            $table->string('pause_scheduled_rides')->nullable()->after('pause_reason');
            // {gate: {confirmed_at, confirmed_by_admin_id, note, value}}
            $table->jsonb('readiness_confirmations')->nullable()->after('pause_reason');

            $table->index('launch_status');
        });

        // Active cities are live. An inactive city that has had trips was
        // switched off after launch, so it is paused; one that never had a
        // trip has not launched yet.
        DB::table('cities')->where('is_active', true)->update([
            'launch_status' => 'live',
            'launched_at' => DB::raw('created_at'),
        ]);

        DB::table('cities')
            ->where('is_active', false)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('rides')->whereColumn('rides.city_id', 'cities.id'))
            ->update([
                'launch_status' => 'paused',
                'launched_at' => DB::raw('created_at'),
                'paused_at' => DB::raw('updated_at'),
            ]);

        Schema::table('cities', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->change();
        });

        Schema::create('city_waitlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('city_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // When the passenger was told the city opened; null while waiting.
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['city_id', 'user_id']);
            $table->index(['city_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_waitlist_entries');

        Schema::table('cities', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->change();
            $table->dropIndex(['launch_status']);
            $table->dropColumn(['launch_status', 'launch_date', 'launched_at', 'paused_at', 'pause_reason', 'pause_scheduled_rides', 'readiness_confirmations']);
        });
    }
};
