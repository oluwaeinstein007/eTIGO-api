<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A dispute decision whose refund needs a second admin (BE2-DISP-07): above
 * the deciding admin's tier limit, or back to the card (Finance). The
 * dispute stays open until it is approved, rejected or withdrawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_refund_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->foreignUuid('requested_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('NGN');
            $table->string('method'); // wallet, card
            $table->boolean('clawback_driver_earnings')->default(true);
            $table->text('resolution_notes');
            $table->string('status')->default('pending');
            $table->foreignUuid('reviewed_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // One pending request per dispute.
        DB::statement(
            "CREATE UNIQUE INDEX dispute_refund_requests_one_pending ON dispute_refund_requests (dispute_id) WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_refund_requests');
    }
};
