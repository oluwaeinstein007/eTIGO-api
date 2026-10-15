<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paystack's last reported status for the payout's transfer (pending,
     * otp, success, ...), so a transfer held for an OTP is flagged at once.
     */
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->string('provider_status', 30)->nullable()->after('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn('provider_status');
        });
    }
};
