<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a station, or removing stalls, archives them: past reservations
 * (and their fees) still point at the station and stall they used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ev_charging_stations', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('ev_charging_stalls', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ev_charging_stalls', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('ev_charging_stations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
