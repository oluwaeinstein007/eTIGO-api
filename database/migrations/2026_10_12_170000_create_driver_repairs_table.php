<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_repairs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference_number')->unique();
            $table->foreignUuid('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('type'); // repair, maintenance
            $table->string('status')->default('in_progress'); // in_progress, scheduled, completed, cancelled
            $table->string('title');
            $table->text('details');
            $table->date('preferred_date');
            $table->string('preferred_time');
            $table->text('summary_note')->nullable();
            $table->json('workshop_visit')->nullable();
            $table->json('appointment')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->json('progress_steps')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index('driver_id');
            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_repairs');
    }
};
