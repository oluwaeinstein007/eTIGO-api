<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->foreignUuid('promo_code_id')
                ->nullable()
                ->after('pricing_snapshot')
                ->constrained('promo_codes')
                ->nullOnDelete();

            $table->decimal('discount_amount', 10, 2)
                ->nullable()
                ->after('promo_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn('discount_amount');
        });
    }
};
