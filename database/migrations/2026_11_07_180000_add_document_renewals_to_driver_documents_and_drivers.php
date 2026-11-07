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
    /** Holds withdrawals and blocks while the migration is rolled back. */
    private const HOLDING_TABLE = 'driver_document_renewals_rollback';

    /** Suspension reason that keeps a blocked driver off the road on a release without this feature. */
    private const ROLLBACK_SUSPENSION = 'Driving licence not valid on E-tiGo (held during a rollback).';

    /**
     * Add the columns, carry over the one-off reminders, and put back anything held during a rollback.
     */
    public function up(): void
    {
        Schema::table('driver_documents', function (Blueprint $table) {
            // Encrypted (cast), so text rather than a short string.
            $table->text('document_number')->nullable()->after('type');
            // The last reminder step sent: days before expiry, or -1 once expired.
            $table->smallInteger('expiry_reminder_stage')->nullable()->after('expiry_reminder_sent_at');
            // Channels of that step that failed and are tried again next run.
            $table->json('expiry_reminder_retry')->nullable()->after('expiry_reminder_stage');
            $table->timestamp('revoked_at')->nullable()->after('reviewed_at');
            $table->foreignUuid('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->timestamp('documents_blocked_at')->nullable()->after('suspension_reason');
            // A withdrawal's reason is up to 500 characters on top of the wording.
            $table->text('documents_block_reason')->nullable()->after('documents_blocked_at');
            $table->index('documents_blocked_at');
        });

        // Papers that already got the one-off reminder (PR #160, sent from 7
        // days before expiry) carry on from the step it stood for, counted
        // in Lagos days, so nobody is told the same thing twice.
        $sentOn = "DATE(expiry_reminder_sent_at AT TIME ZONE 'UTC' AT TIME ZONE 'Africa/Lagos')";
        DB::table('driver_documents')
            ->whereNotNull('expiry_reminder_sent_at')
            ->whereNotNull('expires_at')
            ->update(['expiry_reminder_stage' => DB::raw("CASE
                WHEN expires_at < {$sentOn} THEN -1
                WHEN expires_at = {$sentOn} THEN 0
                WHEN expires_at = {$sentOn} + 1 THEN 1
                ELSE 7 END")]);

        $this->restoreHeldState();
    }

    /**
     * Rolling back must not let a blocked driver drive: blocks and
     * withdrawals are kept in a holding table, and each blocked, approved
     * driver is suspended (which the release without this feature enforces).
     * Running up() again puts it all back.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::HOLDING_TABLE);
        Schema::create(self::HOLDING_TABLE, function (Blueprint $table) {
            $table->string('kind', 20);
            $table->uuid('ref_id');
            $table->json('data');
        });

        $now = now();

        DB::table('driver_documents')->whereNotNull('revoked_at')->orderBy('id')
            ->each(fn ($document) => DB::table(self::HOLDING_TABLE)->insert([
                'kind' => 'withdrawal',
                'ref_id' => $document->id,
                'data' => json_encode(['revoked_at' => $document->revoked_at, 'revoked_by' => $document->revoked_by]),
            ]));

        DB::table('drivers')->whereNotNull('documents_blocked_at')->orderBy('id')
            ->each(function ($driver) use ($now) {
                $suspend = $driver->status === 'approved';

                DB::table(self::HOLDING_TABLE)->insert([
                    'kind' => 'block',
                    'ref_id' => $driver->id,
                    'data' => json_encode([
                        'blocked_at' => $driver->documents_blocked_at,
                        'reason' => $driver->documents_block_reason,
                        'suspended_for_rollback' => $suspend,
                    ]),
                ]);

                if ($suspend) {
                    DB::table('drivers')->where('id', $driver->id)->update([
                        'status' => 'suspended',
                        'suspended_at' => $now,
                        'suspension_reason' => self::ROLLBACK_SUSPENSION,
                        'is_online' => false,
                    ]);
                }
            });

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropIndex(['documents_blocked_at']);
            $table->dropColumn(['documents_blocked_at', 'documents_block_reason']);
        });

        Schema::table('driver_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['document_number', 'expiry_reminder_stage', 'expiry_reminder_retry', 'revoked_at']);
        });
    }

    /**
     * After a rollback: put back withdrawals and blocks, and lift the
     * rollback suspensions that operations haven't changed since.
     */
    private function restoreHeldState(): void
    {
        if (! Schema::hasTable(self::HOLDING_TABLE)) {
            return;
        }

        foreach (DB::table(self::HOLDING_TABLE)->get() as $row) {
            $data = json_decode($row->data, true);

            if ($row->kind === 'withdrawal') {
                DB::table('driver_documents')->where('id', $row->ref_id)->update([
                    'revoked_at' => $data['revoked_at'],
                    // The admin may have been deleted meanwhile.
                    'revoked_by' => DB::table('users')->where('id', $data['revoked_by'])->exists() ? $data['revoked_by'] : null,
                ]);

                continue;
            }

            DB::table('drivers')->where('id', $row->ref_id)->update([
                'documents_blocked_at' => $data['blocked_at'],
                'documents_block_reason' => $data['reason'],
            ]);

            if ($data['suspended_for_rollback']) {
                DB::table('drivers')
                    ->where('id', $row->ref_id)
                    ->where('status', 'suspended')
                    ->where('suspension_reason', self::ROLLBACK_SUSPENSION)
                    ->update(['status' => 'approved', 'suspended_at' => null, 'suspension_reason' => null]);
            }
        }

        Schema::drop(self::HOLDING_TABLE);
    }
};
