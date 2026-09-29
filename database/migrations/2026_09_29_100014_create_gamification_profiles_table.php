<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gamification_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('total_carbon_score', 12, 2)->default(0);
            $table->unsignedInteger('total_ranking_points')->default(0);
            $table->unsignedTinyInteger('current_tier')->default(1);
            $table->timestamp('tier_upgraded_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gamification_profiles');
    }
};
