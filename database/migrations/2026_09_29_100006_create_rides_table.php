<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('city_id')->constrained();
            $table->foreignId('vehicle_class_id')->constrained();
            $table->foreignId('passenger_id')->constrained('users');
            $table->foreignId('driver_id')->nullable()->constrained('users');
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_address');
            $table->decimal('destination_lat', 10, 7);
            $table->decimal('destination_lng', 10, 7);
            $table->string('destination_address');
            $table->string('status');
            $table->char('pin_code', 4)->nullable();
            $table->string('share_token')->unique()->nullable();
            $table->decimal('fare_estimate_amount', 10, 2)->nullable();
            $table->decimal('final_fare_amount', 10, 2)->nullable();
            $table->string('fare_currency', 3)->default('NGN');
            $table->jsonb('pricing_snapshot')->nullable();
            $table->string('payment_method');
            $table->string('payment_status')->default('pending');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'city_id']);
            $table->index(['passenger_id', 'created_at']);
            $table->index(['driver_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rides');
    }
};
