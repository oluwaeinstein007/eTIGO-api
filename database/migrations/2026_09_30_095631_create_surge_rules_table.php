<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surge_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('city_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_class_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type');
            $table->decimal('multiplier', 3, 2);
            $table->jsonb('conditions')->default('{}');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->foreignUuid('created_by_admin_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surge_rules');
    }
};
