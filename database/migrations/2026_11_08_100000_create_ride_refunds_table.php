<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every refund made on a ride (POST /admin/rides/{ride}/refund), so finance
 * can see what was refunded, by whom, why, and whether the driver's earnings
 * were clawed back, without reading the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ride_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('driver_clawback_kobo')->default(0);
            $table->text('reason');
            $table->foreignUuid('refunded_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('dispute_id')->nullable();
            $table->timestamps();

            $table->index(['ride_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_refunds');
    }
};
