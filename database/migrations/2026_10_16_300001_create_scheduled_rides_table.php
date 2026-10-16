<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_rides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ride_id')->unique()->constrained('rides')->cascadeOnDelete();
            $table->timestamp('scheduled_pickup_at');
            // When the reminder went out; set at booking instead when the
            // pickup is already inside the reminder window.
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // Why the dispatcher could not start matching (the ride is then
            // cancelled by the system): insufficient_wallet_balance,
            // wallet_unavailable, account_suspended, service_unavailable,
            // missed_dispatch_window.
            $table->string('dispatch_failure_reason')->nullable();

            // A driver who committed to the booking in advance (or one
            // operations assigned). At dispatch the ride goes straight to them
            // when they are free; otherwise it falls back to normal matching.
            $table->foreignUuid('reserved_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at')->nullable();
            $table->foreignUuid('reserved_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('driver_reminder_sent_at')->nullable();
            // Why a reservation was dropped: driver_released, admin_released,
            // pickup_changed, driver_unavailable (offline or busy at dispatch).
            $table->string('reservation_released_reason')->nullable();

            // Late-cancellation fee (passenger cancels a reserved booking close
            // to pickup): amount in NGN and pending / charged / waived / failed.
            $table->decimal('late_cancellation_fee', 10, 2)->nullable();
            $table->string('late_cancellation_fee_status')->nullable();
            $table->string('late_cancellation_fee_note')->nullable();
            $table->timestamps();

            $table->index(['reserved_driver_id', 'scheduled_pickup_at']);

            // The dispatcher and reminder sweeps scan undispatched bookings by time.
            $table->index(['dispatched_at', 'cancelled_at', 'scheduled_pickup_at'], 'scheduled_rides_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_rides');
    }
};
