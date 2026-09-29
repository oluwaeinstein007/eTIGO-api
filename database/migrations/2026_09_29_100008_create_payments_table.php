<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('ride_id');
            $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('NGN');
            $table->string('method');
            $table->string('gateway_transaction_id')->nullable();
            $table->string('gateway_payment_method_id')->nullable();
            $table->decimal('tip_amount', 10, 2)->default(0);
            $table->string('status')->default('pending');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('ride_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
