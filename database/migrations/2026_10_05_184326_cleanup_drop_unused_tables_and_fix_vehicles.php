<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ev_reservations');
        Schema::dropIfExists('ev_charging_stalls');
        Schema::dropIfExists('ev_charging_stations');

        Schema::dropIfExists('trip_carbon_scores');
        Schema::dropIfExists('point_multiplier_configs');
        Schema::dropIfExists('tier_configs');
        Schema::dropIfExists('gamification_profiles');

        Schema::dropIfExists('sos_event_log');
        Schema::dropIfExists('sos_incidents');

        Schema::dropIfExists('offline_trip_flags');

        Schema::dropIfExists('admin_invitations');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['vehicle_class', 'vehicle_class_approved', 'class_approved_by']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('vehicle_class')->nullable();
            $table->boolean('vehicle_class_approved')->default(false);
            $table->foreignUuid('class_approved_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
