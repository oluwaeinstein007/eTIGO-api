<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every refund made on a ride (POST /admin/rides/{ride}/refund), so finance
 * can see what was refunded, by whom, why, and whether the driver's earnings
 * were clawed back, without reading the audit log. Backfilled from the
 * ledger so refunds made before this table existed show up too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ride_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('driver_clawback_kobo')->default(0);
            $table->text('reason');
            $table->foreignUuid('refunded_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('dispute_id')->nullable();
            $table->timestamps();

            $table->index(['ride_id', 'created_at']);
        });

        // Every refund journal (idempotency_key "refund-{ride_id}-...") becomes
        // a row, with its matching clawback journal's amount if there is one.
        // Only journals pointing at a ride that still exists are backfilled,
        // so the new table's foreign key never fails.
        DB::statement(<<<'SQL'
            INSERT INTO ride_refunds (id, ride_id, amount_kobo, driver_clawback_kobo, reason, refunded_by_admin_id, dispute_id, created_at, updated_at)
            SELECT
                gen_random_uuid(),
                r.id,
                refund_sum.total,
                COALESCE(clawback_sum.total, 0),
                COALESCE(j.metadata->>'reason', j.description),
                (j.metadata->>'refunded_by_admin_id')::uuid,
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
            WHERE j.idempotency_key LIKE 'refund-%'
              AND jsonb_exists(j.metadata, 'ride_id')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_refunds');
    }
};
