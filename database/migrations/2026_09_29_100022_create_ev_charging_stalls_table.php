<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ev_charging_stalls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('station_id')->constrained('ev_charging_stations')->cascadeOnDelete();
            $table->unsignedInteger('stall_number');
            $table->string('status')->default('available');
            $table->foreignUuid('current_vehicle_driver_id')->nullable()->constrained('users');
            $table->timestamp('occupied_since')->nullable();
            $table->timestamp('estimated_departure_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['station_id', 'stall_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ev_charging_stalls');
    }
};
