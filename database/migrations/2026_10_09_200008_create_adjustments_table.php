<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount');
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->foreignUuid('created_by_admin_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('approved_by_admin_id')->nullable();
            $table->foreign('approved_by_admin_id')->references('id')->on('users')->nullOnDelete();
            $table->uuid('journal_id')->nullable();
            $table->foreign('journal_id')->references('id')->on('journals')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustments');
    }
};
