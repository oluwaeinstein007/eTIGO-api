<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('rate', 5, 4);
            $table->uuid('driver_id')->nullable();
            $table->foreign('driver_id')->references('id')->on('drivers')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['driver_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_configs');
    }
};
