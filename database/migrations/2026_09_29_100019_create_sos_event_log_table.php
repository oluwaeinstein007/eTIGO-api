<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_event_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_id')->constrained('sos_incidents')->cascadeOnDelete();
            $table->string('event_type');
            $table->uuid('actor_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_event_log');
    }
};
