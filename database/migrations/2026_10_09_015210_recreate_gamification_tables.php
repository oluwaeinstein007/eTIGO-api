<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gamification_profiles')) {
            Schema::create('gamification_profiles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->decimal('total_carbon_score', 12, 2)->default(0);
                $table->unsignedInteger('total_ranking_points')->default(0);
                $table->unsignedTinyInteger('current_tier')->default(1);
                $table->timestamp('tier_upgraded_at')->nullable();
                $table->timestamps();

                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('tier_configs')) {
            Schema::create('tier_configs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedTinyInteger('tier_level')->unique();
                $table->string('tier_name');
                $table->unsignedInteger('min_points_required');
                $table->decimal('booking_fee_discount_pct', 5, 2)->default(0);
                $table->boolean('ev_reservation_fee_waived')->default(false);
                $table->boolean('priority_matching_enabled')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('point_multiplier_configs')) {
            Schema::create('point_multiplier_configs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('condition_type')->unique();
                $table->decimal('multiplier_value', 5, 2);
                $table->boolean('is_stackable')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('trip_carbon_scores')) {
            Schema::create('trip_carbon_scores', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ride_id');
                $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
                $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_carbon_scores');
        Schema::dropIfExists('point_multiplier_configs');
        Schema::dropIfExists('tier_configs');
        Schema::dropIfExists('gamification_profiles');
    }
};
