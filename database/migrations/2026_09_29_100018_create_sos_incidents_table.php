<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('ride_id');
            $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
            $table->foreignId('triggered_by_user_id')->constrained('users');
            $table->string('trigger_type');
            $table->string('status')->default('triggered');
            $table->decimal('gps_lat', 10, 7);
            $table->decimal('gps_lng', 10, 7);
            $table->jsonb('vehicle_details')->nullable();
            $table->jsonb('telemetry_data')->nullable();
            $table->timestamp('check_in_sent_at')->nullable();
            $table->timestamp('check_in_acknowledged_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users');
            $table->text('operator_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
            $table->index('ride_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_incidents');
    }
};
