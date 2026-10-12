<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ev_reservations', function (Blueprint $table) {
            $table->uuid('station_id')->nullable()->change();
            $table->string('type')->default('charging')->nullable();
            $table->unsignedSmallInteger('battery_level')->nullable();
            $table->date('requested_date')->nullable();
            $table->string('requested_time')->nullable();
            $table->text('notes')->nullable();
            $table->string('approver_name')->nullable();
            $table->string('confirmed_slot')->nullable();
            $table->string('check_in_time')->nullable();
            $table->unsignedInteger('queue_ahead_count')->nullable();
            $table->string('queue_ahead_description')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ev_reservations', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'battery_level',
                'requested_date',
                'requested_time',
                'notes',
                'approver_name',
                'confirmed_slot',
                'check_in_time',
                'queue_ahead_count',
                'queue_ahead_description',
                'updated_at',
            ]);
        });
    }
};
