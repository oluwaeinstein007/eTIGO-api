<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tier_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('tier_level')->unique();
            $table->string('tier_name');
            $table->unsignedInteger('min_points_required');
            $table->decimal('booking_fee_discount_pct', 5, 2)->default(0);
            $table->boolean('ev_reservation_fee_waived')->default(false);
            $table->boolean('priority_matching_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tier_configs');
    }
};
