<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_remittances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained('fleet_agreements')->cascadeOnDelete();
            $table->foreignUuid('driver_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('target_amount', 12, 2);
            $table->decimal('remitted_amount', 12, 2)->default(0);
            $table->decimal('shortfall_amount', 12, 2)->default(0);
            $table->timestamp('target_met_at')->nullable();
            $table->integer('ride_count')->default(0);
            $table->decimal('total_fares', 12, 2)->default(0);
            $table->decimal('driver_earnings', 12, 2)->default(0);
            $table->boolean('settled')->default(false);
            $table->timestamps();

            $table->unique(['agreement_id', 'date']);
            $table->index(['driver_id', 'date']);
            $table->index(['settled', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_remittances');
    }
};
