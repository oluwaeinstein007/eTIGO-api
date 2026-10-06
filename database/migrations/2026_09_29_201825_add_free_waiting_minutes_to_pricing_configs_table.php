<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pricing_configs', 'free_waiting_minutes')) {
            return;
        }

        Schema::table('pricing_configs', function (Blueprint $table) {
            $table->unsignedSmallInteger('free_waiting_minutes')->default(5)->after('waiting_time_rate');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            $table->dropColumn('free_waiting_minutes');
        });
    }
};
