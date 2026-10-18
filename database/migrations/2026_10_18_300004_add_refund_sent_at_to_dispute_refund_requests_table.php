<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an approved card refund was sent to the payment provider. Set before
 * the provider is called, so a failure after the provider has refunded can
 * never lead to a second refund for the same request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispute_refund_requests', function (Blueprint $table) {
            $table->timestamp('refund_sent_at')->nullable()->after('review_notes');
        });
    }

    public function down(): void
    {
        Schema::table('dispute_refund_requests', function (Blueprint $table) {
            $table->dropColumn('refund_sent_at');
        });
    }
};
