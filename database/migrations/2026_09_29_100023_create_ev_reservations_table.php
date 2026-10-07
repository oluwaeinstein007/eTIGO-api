<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};
