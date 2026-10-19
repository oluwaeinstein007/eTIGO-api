<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exclusive promotions (G-07) become a perk switched on per tier, as the
 * admin design shows. A tier-only promo (minimum tier above Bronze) can be
 * used only by tiers that reach its minimum and have this perk on. Silver
 * and up could already use tier-only promos, so they start with it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tier_configs', function (Blueprint $table) {
            $table->boolean('exclusive_promotions_enabled')->default(false)->after('priority_matching_enabled');
        });

        DB::table('tier_configs')->where('tier_level', '>=', 2)->update(['exclusive_promotions_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('tier_configs', function (Blueprint $table) {
            $table->dropColumn('exclusive_promotions_enabled');
        });
    }
};
