<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surge_rules', function (Blueprint $table) {
            $table->index(['city_id', 'is_active', 'effective_from', 'effective_until'], 'surge_rules_active_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('surge_rules', function (Blueprint $table) {
            $table->dropIndex('surge_rules_active_lookup_index');
        });
    }
};
