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
            // wallet_unavailable, service_unavailable, missed_dispatch_window.
            $table->string('dispatch_failure_reason')->nullable();
            $table->timestamps();

            // The dispatcher and reminder sweeps scan undispatched bookings by time.
            $table->index(['dispatched_at', 'cancelled_at', 'scheduled_pickup_at'], 'scheduled_rides_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_rides');
    }
};
