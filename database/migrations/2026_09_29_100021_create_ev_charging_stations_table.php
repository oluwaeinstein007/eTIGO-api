<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ev_charging_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('city_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address');
            $table->unsignedInteger('total_stalls');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ev_charging_stations');
    }
};
