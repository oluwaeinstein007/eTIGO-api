<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_trip_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ride_id');
            $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
            $table->foreignUuid('driver_id')->constrained('users');
            $table->foreignUuid('passenger_id')->nullable()->constrained('users');
            $table->jsonb('detection_data')->nullable();
            $table->unsignedTinyInteger('sanction_tier');
            $table->string('sanction_action');
            $table->boolean('is_disputed')->default(false);
            $table->text('dispute_notes')->nullable();
            $table->foreignUuid('dispute_resolved_by_admin_id')->nullable()->constrained('users');
            $table->string('dispute_outcome')->nullable();
            $table->timestamp('flagged_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('driver_id');
            $table->index('ride_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_trip_flags');
    }
};
