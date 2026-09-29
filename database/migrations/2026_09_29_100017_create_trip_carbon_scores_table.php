<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_carbon_scores', function (Blueprint $table) {
            $table->id();
            $table->uuid('ride_id');
            $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('distance_km', 10, 2);
            $table->decimal('baseline_emission', 10, 4);
            $table->decimal('vehicle_emission', 10, 4);
            $table->decimal('co2_saved', 10, 4);
            $table->unsignedInteger('base_points');
            $table->decimal('multiplier_applied', 5, 2)->default(1.00);
            $table->string('multiplier_reason')->nullable();
            $table->unsignedInteger('final_points');
            $table->timestamp('created_at')->useCurrent();

            $table->index('ride_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_carbon_scores');
    }
};
