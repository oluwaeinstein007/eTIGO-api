<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ev_charging_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('city_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address');
            $table->unsignedInteger('total_stalls');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('city_id');
        });

        Schema::create('ev_charging_stalls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('station_id')->constrained('ev_charging_stations')->cascadeOnDelete();
            $table->unsignedInteger('stall_number');
            $table->string('status')->default('available');
            $table->foreignUuid('current_vehicle_driver_id')->nullable()->constrained('users');
            $table->timestamp('occupied_since')->nullable();
            $table->timestamp('estimated_departure_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('station_id');
        });

        Schema::create('ev_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stall_id')->nullable()->constrained('ev_charging_stalls');
            $table->foreignUuid('station_id')->constrained('ev_charging_stations')->cascadeOnDelete();
            $table->foreignUuid('driver_id')->constrained('users');
            $table->string('status')->default('reserved');
            $table->unsignedInteger('queue_position')->nullable();
            $table->timestamp('estimated_available_at')->nullable();
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->boolean('fee_waived')->default(false);
            $table->timestamp('reserved_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('station_id');
            $table->index('driver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ev_reservations');
        Schema::dropIfExists('ev_charging_stalls');
        Schema::dropIfExists('ev_charging_stations');
    }
};
