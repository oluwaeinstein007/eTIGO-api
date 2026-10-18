<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An unpaid late-cancellation fee written off when a fee dispute is upheld.
 * Kept apart from refund_amount, which is money actually paid back, so a
 * waiver never reads as a wallet credit or adds to refund totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->decimal('waived_amount', 10, 2)->nullable()->after('refund_amount');
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropColumn('waived_amount');
        });
    }
};
