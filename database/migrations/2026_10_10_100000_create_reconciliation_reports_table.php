<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('report_date')->unique();
            $table->bigInteger('gateway_charges_total')->default(0);
            $table->bigInteger('ledger_credits_total')->default(0);
            $table->bigInteger('gateway_transfers_total')->default(0);
            $table->bigInteger('ledger_payouts_total')->default(0);
            $table->integer('mismatches_count')->default(0);
            $table->json('mismatches')->nullable();
            $table->string('status')->default('clean');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_reports');
    }
};
