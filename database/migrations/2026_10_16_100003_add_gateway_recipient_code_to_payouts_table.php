<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Paystack recipient code a payout is sent to, saved before the
     * transfer starts so the transfer approval URL can confirm that Paystack
     * is about to pay the driver's account. The approval looks the payout up
     * by reference, so a reference must belong to one payout only.
     */
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->string('gateway_recipient_code')->nullable()->after('gateway_reference');
            $table->unique('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropUnique(['gateway_reference']);
            $table->dropColumn('gateway_recipient_code');
        });
    }
};
