<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Providers reuse the same object id across different events (e.g. a
     * transfer that succeeds and is later reversed), so a delivery is only a
     * duplicate when the provider, object id and event type all match.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropUnique(['provider', 'event_id']);
            $table->unique(['provider', 'event_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropUnique(['provider', 'event_id', 'event_type']);
            $table->unique(['provider', 'event_id']);
        });
    }
};
