<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('discount_type');
            $table->decimal('discount_value', 10, 2);
            $table->decimal('max_discount_cap', 10, 2)->nullable();
            $table->unsignedInteger('total_redemption_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->jsonb('geo_fence')->nullable();
            $table->unsignedInteger('min_order_count')->nullable();
            $table->unsignedInteger('max_order_count')->nullable();
            $table->unsignedTinyInteger('min_tier_level')->nullable();
            $table->boolean('peak_only')->default(false);
            $table->boolean('off_peak_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
