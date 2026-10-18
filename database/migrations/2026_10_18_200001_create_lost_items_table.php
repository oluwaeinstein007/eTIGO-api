<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lost & Found (Phase 2, BE2-LF-01): an item left in the car after a
     * completed trip, reported by the passenger or handed in by the driver,
     * and the messages passenger, driver and support exchange about it.
     */
    public function up(): void
    {
        Schema::create('lost_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference_number')->nullable()->unique();
            $table->foreignUuid('ride_id')->constrained('rides')->cascadeOnDelete();
            // The ride's passenger (the booker on a third-party ride) and driver.
            $table->foreignUuid('passenger_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('driver_id')->nullable()->constrained('users')->nullOnDelete();
            // passenger (lost it) or driver (found it in the car)
            $table->string('reported_by');
            $table->foreignUuid('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category');
            $table->text('description');
            $table->json('photos')->nullable();
            // Another number to reach the passenger on, often because the
            // lost item is their phone.
            $table->string('contact_phone', 20)->nullable();
            // reported, driver_notified, driver_confirmed, driver_denied,
            // returned, resolved, unresolved or cancelled
            $table->string('status')->default('reported');
            $table->text('driver_response')->nullable();
            $table->timestamp('driver_notified_at')->nullable();
            $table->timestamp('driver_responded_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            // Handed to support: by the passenger, or automatically when the
            // driver does not answer or the return is overdue.
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalation_reason', 500)->nullable();
            // passenger or system (no driver answer, return overdue, no driver)
            $table->string('escalation_source')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignUuid('resolved_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            // The return fee quoted when the driver confirmed (0 or null = none),
            // and pending, charged, balance_due or waived once it is due.
            $table->decimal('return_fee', 10, 2)->nullable();
            $table->string('return_fee_status')->nullable();
            $table->timestamps();

            $table->index(['passenger_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['status', 'escalated_at']);
            $table->index('ride_id');
        });

        Schema::create('lost_item_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lost_item_id')->constrained('lost_items')->cascadeOnDelete();
            $table->foreignUuid('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            // passenger, driver or support
            $table->string('sender_role');
            $table->text('body');
            // Support's internal notes, never shown to passenger or driver.
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['lost_item_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // One open report per ride, whoever filed it.
            DB::statement("CREATE UNIQUE INDEX lost_items_one_open_per_ride ON lost_items (ride_id) WHERE status NOT IN ('resolved', 'unresolved', 'cancelled')");
            DB::statement('CREATE SEQUENCE IF NOT EXISTS lost_items_reference_number_seq OWNED BY lost_items.reference_number');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_item_messages');
        Schema::dropIfExists('lost_items');
    }
};
