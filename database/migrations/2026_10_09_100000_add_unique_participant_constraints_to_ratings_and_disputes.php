<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->unique(['ride_id', 'rated_by_user_id']);
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->unique(['ride_id', 'reported_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropUnique(['ride_id', 'rated_by_user_id']);
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropUnique(['ride_id', 'reported_by_user_id']);
        });
    }
};
