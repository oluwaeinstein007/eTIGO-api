<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('status')->default('requested');
            $table->string('gateway_transfer_id')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by_admin_id')->nullable();
            $table->foreign('approved_by_admin_id')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
