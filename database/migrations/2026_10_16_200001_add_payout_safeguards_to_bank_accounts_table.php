<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payout safeguards on a driver's bank account: a hold on payouts after the
     * account replaces another, and the result of comparing its name with the
     * driver's verified name (a mismatch waits for finance to review it).
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->timestamp('payouts_held_until')->nullable()->after('is_primary');
            $table->string('verified_name')->nullable()->after('payouts_held_until');
            $table->string('name_match_status')->nullable()->after('verified_name');
            $table->string('review_status')->nullable()->after('name_match_status');
            $table->text('review_note')->nullable()->after('review_status');
            $table->foreignUuid('reviewed_by_admin_id')->nullable()->after('review_note')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_admin_id');

            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropConstrainedForeignId('reviewed_by_admin_id');
            $table->dropColumn(['payouts_held_until', 'verified_name', 'name_match_status', 'review_status', 'review_note', 'reviewed_at']);
        });
    }
};
