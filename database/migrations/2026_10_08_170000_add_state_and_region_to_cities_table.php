<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('state')->nullable()->after('name');
            $table->string('region')->nullable()->after('state');
            $table->decimal('area_sq_km', 10, 2)->nullable()->after('boundary');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['state', 'region', 'area_sq_km']);
        });
    }
};
