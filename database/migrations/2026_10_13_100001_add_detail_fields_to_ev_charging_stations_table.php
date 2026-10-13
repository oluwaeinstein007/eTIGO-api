<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ev_charging_stations', function (Blueprint $table) {
            $table->json('connector_types')->nullable()->after('total_stalls');
            $table->unsignedInteger('power_output_kw')->nullable()->after('connector_types');
            $table->unsignedInteger('pricing_per_kwh')->nullable()->after('power_output_kw');
            $table->json('operating_hours')->nullable()->after('pricing_per_kwh');
        });
    }

    public function down(): void
    {
        Schema::table('ev_charging_stations', function (Blueprint $table) {
            $table->dropColumn(['connector_types', 'power_output_kw', 'pricing_per_kwh', 'operating_hours']);
        });
    }
};
