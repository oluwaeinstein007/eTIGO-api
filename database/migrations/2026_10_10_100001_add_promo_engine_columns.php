<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->text('description')->nullable()->after('code');
            $table->foreignUuid('city_id')->nullable()->after('off_peak_only')->constrained()->nullOnDelete();
            $table->foreignUuid('vehicle_class_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
            $table->decimal('minimum_fare_amount', 10, 2)->nullable()->after('vehicle_class_id');
            $table->foreignUuid('created_by_admin_id')->nullable()->after('minimum_fare_amount')->constrained('users')->nullOnDelete();
        });

        Schema::table('promo_redemptions', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('promo_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
            $table->dropConstrainedForeignId('vehicle_class_id');
            $table->dropConstrainedForeignId('created_by_admin_id');
            $table->dropColumn(['description', 'minimum_fare_amount']);
        });

        Schema::table('promo_redemptions', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['promo_code_id']);
        });
    }
};
