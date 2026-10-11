<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_tx_ref')->nullable()->after('gateway_transaction_id');
            $table->string('gateway_payment_link')->nullable()->after('gateway_tx_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['gateway_tx_ref', 'gateway_payment_link']);
        });
    }
};
