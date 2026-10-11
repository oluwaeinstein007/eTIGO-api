<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount_collected', 10, 2)->nullable()->after('amount');
            $table->decimal('cash_change_amount', 10, 2)->nullable()->after('amount_collected');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['amount_collected', 'cash_change_amount']);
        });
    }
};
