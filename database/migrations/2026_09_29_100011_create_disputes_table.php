<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ride_id');
            $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
            $table->foreignUuid('reported_by_user_id')->constrained('users');
            $table->string('category');
            $table->text('description');
            $table->string('status')->default('open');
            $table->text('resolution_notes')->nullable();
            $table->foreignUuid('resolved_by_admin_id')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('ride_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
