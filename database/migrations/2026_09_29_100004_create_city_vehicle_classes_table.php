<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_vehicle_classes', function (Blueprint $table) {
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_class_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);

            $table->unique(['city_id', 'vehicle_class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_vehicle_classes');
    }
};
