<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignUuid('driver_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('vehicles')->whereNull('driver_id')->delete();

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignUuid('driver_id')->nullable(false)->change();
        });
    }
};
