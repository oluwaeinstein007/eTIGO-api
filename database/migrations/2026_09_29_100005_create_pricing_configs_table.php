<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('city_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_class_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_fare', 10, 2);
            $table->decimal('per_km_rate', 10, 2);
            $table->decimal('per_minute_rate', 10, 2);
            $table->decimal('minimum_fare', 10, 2);
            $table->decimal('waiting_time_rate', 10, 2)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('effective_from');
            $table->foreignUuid('created_by_admin_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_configs');
    }
};
