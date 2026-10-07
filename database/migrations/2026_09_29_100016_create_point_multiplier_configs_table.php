<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_multiplier_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('condition_type');
            $table->decimal('multiplier_value', 5, 2);
            $table->boolean('is_stackable')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_multiplier_configs');
    }
};
