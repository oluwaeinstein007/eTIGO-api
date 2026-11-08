<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `ride_refunds` so far only ever got a "fare" refund (POST
 * /admin/rides/{ride}/refund). Cancellation-fee refunds
 * (ScheduledRideCancellationFeeService::refund) write here too now, so a
 * ride's fare-refundable balance (WalletRefundService::refundedAmountKobo)
 * must be able to tell the two apart instead of lumping them together.
 *
 * Also backfills every refund made before `ride_refunds` existed, from the
 * ledger, now that the table exists but is still empty on every
 * environment that already ran the migration that created it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_refunds', function (Blueprint $table) {
            $table->string('category')->default('fare')->after('driver_clawback_kobo');
        });

        // Every refund journal (idempotency_key "refund-{ride_id}-...") becomes
        // a row, with its matching clawback journal's amount if there is one.
        // Only journals pointing at a ride that still exists are backfilled,
        // so the table's foreign key never fails; an admin who was since
        // deleted backfills as null rather than failing the same way.
        DB::statement(<<<'SQL'
            INSERT INTO ride_refunds (id, ride_id, amount_kobo, driver_clawback_kobo, category, reason, refunded_by_admin_id, dispute_id, created_at, updated_at)
            SELECT
                gen_random_uuid(),
                r.id,
                refund_sum.total,
                COALESCE(clawback_sum.total, 0),
                CASE WHEN j.metadata->>'category' = 'scheduled_late_cancellation_fee_refund' THEN 'cancellation_fee' ELSE 'fare' END,
                COALESCE(j.metadata->>'reason', j.description),
                admin.id,
                (j.metadata->>'dispute_id')::uuid,
                j.created_at,
                j.created_at
            FROM journals j
            JOIN rides r ON r.id = (j.metadata->>'ride_id')::uuid
            JOIN LATERAL (
                SELECT COALESCE(SUM(le.amount), 0) AS total
                FROM ledger_entries le
                WHERE le.journal_id = j.id AND le.type = 'credit'
            ) refund_sum ON true
            LEFT JOIN journals cj ON cj.idempotency_key = 'clawback-' || j.idempotency_key
            LEFT JOIN LATERAL (
                SELECT COALESCE(SUM(le.amount), 0) AS total
                FROM ledger_entries le
                WHERE le.journal_id = cj.id AND le.type = 'debit'
            ) clawback_sum ON true
            LEFT JOIN users admin ON admin.id = (j.metadata->>'refunded_by_admin_id')::uuid
            WHERE j.idempotency_key LIKE 'refund-%'
              AND jsonb_exists(j.metadata, 'ride_id')
              AND NOT EXISTS (
                  SELECT 1 FROM ride_refunds existing
                  WHERE existing.ride_id = r.id AND existing.amount_kobo = refund_sum.total AND existing.created_at = j.created_at
              )
        SQL);
    }

    public function down(): void
    {
        Schema::table('ride_refunds', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
