<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('driver_id')->constrained()->cascadeOnDelete();
            $table->string('make');
            $table->string('model');
            $table->string('colour');
            $table->string('plate_number')->unique();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('vehicle_class')->nullable();
            $table->boolean('vehicle_class_approved')->default(false);
            $table->foreignUuid('class_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('driver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
