<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 advanced disputes (OQ-21): queue and tier, assignment, response
 * targets, appeals, links between the two sides' disputes on one trip, and
 * the SOS incident behind a safety case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->string('queue')->default('support')->after('status');
            $table->unsignedTinyInteger('escalation_tier')->default(1)->after('queue');
            $table->foreignUuid('assigned_admin_id')->nullable()->after('escalation_tier')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_admin_id');
            $table->foreignUuid('escalated_by_admin_id')->nullable()->after('assigned_at')->constrained('users')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable()->after('escalated_by_admin_id');
            $table->text('escalation_reason')->nullable()->after('escalated_at');
            // admin, system (missed target) or appeal
            $table->string('escalation_source')->nullable()->after('escalation_reason');
            $table->timestamp('sla_started_at')->nullable()->after('escalation_source');
            $table->timestamp('first_response_due_at')->nullable()->after('sla_started_at');
            $table->timestamp('resolution_due_at')->nullable()->after('first_response_due_at');
            $table->timestamp('first_responded_at')->nullable()->after('resolution_due_at');
            $table->timestamp('first_response_breached_at')->nullable()->after('first_responded_at');
            $table->timestamp('resolution_breached_at')->nullable()->after('first_response_breached_at');
            // The tier the decision was made at: the case's tier, or the
            // deciding admin's if higher. An appeal goes one above it.
            $table->unsignedTinyInteger('decision_tier')->nullable()->after('resolved_at');
            $table->timestamp('appealed_at')->nullable()->after('decision_tier');
            $table->text('appeal_reason')->nullable()->after('appealed_at');
            $table->foreignUuid('appeal_of_resolver_id')->nullable()->after('appeal_reason')->constrained('users')->nullOnDelete();
            $table->json('original_decision')->nullable()->after('appeal_of_resolver_id');
            $table->foreignUuid('linked_dispute_id')->nullable()->after('original_decision')->constrained('disputes')->nullOnDelete();
            $table->foreignUuid('sos_incident_id')->nullable()->after('linked_dispute_id')->constrained('sos_incidents')->nullOnDelete();
            $table->decimal('driver_clawback_amount', 12, 2)->nullable()->after('refund_currency');
            $table->string('refund_method')->nullable()->after('driver_clawback_amount');

            $table->index(['queue', 'escalation_tier', 'status']);
            $table->index('assigned_admin_id');
            $table->index('resolution_due_at');
        });

        // Existing safety disputes move to the Safety queue; everything else
        // stays at tier 1. Open cases get targets counted from now, so the
        // first sweep does not escalate the whole backlog at once.
        DB::table('disputes')->where('category', 'safety_concern')->update([
            'queue' => 'safety',
            'escalation_tier' => 2,
        ]);

        $now = now();
        $open = fn () => DB::table('disputes')->whereIn('status', ['open', 'under_review']);

        $open()->where('queue', 'safety')->update([
            'sla_started_at' => $now,
            'first_response_due_at' => $now->copy()->addHour(),
            'resolution_due_at' => $now->copy()->addDay(),
        ]);
        $open()->where('queue', 'support')->update([
            'sla_started_at' => $now,
            'first_response_due_at' => $now->copy()->addDay(),
            'resolution_due_at' => $now->copy()->addHours(72),
        ]);
        // Already being worked on: the first response has happened.
        DB::table('disputes')->where('status', 'under_review')->update([
            'first_responded_at' => $now,
        ]);

        // A card refund given before wallet refunds existed.
        DB::table('disputes')->whereNotNull('refund_amount')->update(['refund_method' => 'card']);

        DB::table('disputes')->whereIn('status', ['resolved', 'dismissed'])->update([
            'decision_tier' => DB::raw('escalation_tier'),
        ]);
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropIndex(['queue', 'escalation_tier', 'status']);
            $table->dropIndex(['assigned_admin_id']);
            $table->dropIndex(['resolution_due_at']);
            $table->dropConstrainedForeignId('assigned_admin_id');
            $table->dropConstrainedForeignId('escalated_by_admin_id');
            $table->dropConstrainedForeignId('appeal_of_resolver_id');
            $table->dropConstrainedForeignId('linked_dispute_id');
            $table->dropConstrainedForeignId('sos_incident_id');
            $table->dropColumn([
                'queue', 'escalation_tier', 'assigned_at', 'escalated_at', 'escalation_reason',
                'escalation_source', 'sla_started_at', 'first_response_due_at', 'resolution_due_at',
                'first_responded_at', 'first_response_breached_at', 'resolution_breached_at',
                'decision_tier', 'appealed_at', 'appeal_reason', 'original_decision', 'driver_clawback_amount', 'refund_method',
            ]);
        });
    }
};
