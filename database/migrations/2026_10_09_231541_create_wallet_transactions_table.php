<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->string('reference')->unique();
            $table->bigInteger('amount');
            $table->string('status')->default('pending');
            $table->string('gateway_transaction_id')->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained('journals');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
