<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Driver document renewals: the number printed on a renewed paper, which
 * reminder the driver last got, papers operations withdrew, and the block
 * an expired or withdrawn driving licence puts on the driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_documents', function (Blueprint $table) {
            // Encrypted (cast), so text rather than a short string.
            $table->text('document_number')->nullable()->after('type');
            // The last reminder step sent: days before expiry, or -1 once expired.
            $table->smallInteger('expiry_reminder_stage')->nullable()->after('expiry_reminder_sent_at');
            $table->timestamp('revoked_at')->nullable()->after('reviewed_at');
            $table->foreignUuid('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->timestamp('documents_blocked_at')->nullable()->after('suspension_reason');
            $table->string('documents_block_reason', 500)->nullable()->after('documents_blocked_at');
            $table->index('documents_blocked_at');
        });

        // Papers that already got the one-off reminder (PR #160) carry on
        // from the step it stood for, so nobody is told twice.
        DB::table('driver_documents')
            ->whereNotNull('expiry_reminder_sent_at')
            ->update(['expiry_reminder_stage' => DB::raw('CASE WHEN expires_at < DATE(expiry_reminder_sent_at) THEN -1 ELSE 7 END')]);
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropIndex(['documents_blocked_at']);
            $table->dropColumn(['documents_blocked_at', 'documents_block_reason']);
        });

        Schema::table('driver_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['document_number', 'expiry_reminder_stage', 'revoked_at']);
        });
    }
};
