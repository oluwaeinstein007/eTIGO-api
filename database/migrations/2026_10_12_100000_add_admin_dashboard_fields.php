<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->text('suspension_reason')->nullable()->after('suspended_at');
            $table->string('reference_number')->nullable()->unique()->after('id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('suspension_reason')->nullable()->after('is_active');
            $table->string('reference_number')->nullable()->unique()->after('id');
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->unique()->after('id');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['suspension_reason', 'reference_number']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspension_reason', 'reference_number']);
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropColumn('reference_number');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('reference_number');
        });
    }
};
