<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vehicles record their fuel type (only plug-in vehicles may reserve EV
     * charging stalls), and reservations record when their fee was charged
     * and refunded.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('fuel_type', 20)->nullable()->after('year');
        });

        Schema::table('ev_reservations', function (Blueprint $table) {
            $table->timestamp('fee_charged_at')->nullable()->after('fee_waived');
            $table->timestamp('fee_refunded_at')->nullable()->after('fee_charged_at');
        });
    }

    public function down(): void
    {
        Schema::table('ev_reservations', function (Blueprint $table) {
            $table->dropColumn(['fee_charged_at', 'fee_refunded_at']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('fuel_type');
        });
    }
};
