<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_state_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ride_id');
            $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('triggered_by_type');
            $table->uuid('triggered_by_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('ride_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_state_transitions');
    }
};
