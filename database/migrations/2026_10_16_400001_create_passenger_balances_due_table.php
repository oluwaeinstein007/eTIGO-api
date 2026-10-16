<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money a passenger owes E-tiGo that could not be collected at the time:
     * a scheduled ride's late-cancellation fee (cash or bank transfer, or a
     * wallet or card that could not pay) or the part of a wallet ride's fare
     * the wallet could not cover. New bookings are refused until it is paid.
     */
    public function up(): void
    {
        Schema::create('passenger_balances_due', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('passenger_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            // late_cancellation_fee or wallet_shortfall
            $table->string('reason');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('NGN');
            // The driver the money is owed to (paid out when collected).
            $table->foreignUuid('payee_driver_id')->nullable()->constrained('users')->nullOnDelete();
            // outstanding, paid or waived
            $table->string('status')->default('outstanding');
            $table->timestamp('paid_at')->nullable();
            // wallet or card
            $table->string('paid_via')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->foreignUuid('waived_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('waive_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['ride_id', 'reason']);
            $table->index(['passenger_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passenger_balances_due');
    }
};
