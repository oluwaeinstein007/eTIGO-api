<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third-party bookings (Phase 2, BE2-3P-01): a passenger (the booker, who
 * stays `passenger_id`) books and pays for a ride someone else takes. The
 * PIN and tracking recipients are a snapshot of the policy at booking, so a
 * later settings change never affects rides already booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->boolean('is_third_party')->default(false)->after('passenger_id');
            $table->string('rider_name', 100)->nullable()->after('is_third_party');
            $table->string('rider_phone', 20)->nullable()->after('rider_name');
            $table->string('pin_recipient', 10)->nullable()->after('rider_phone');
            $table->string('tracking_recipient', 10)->nullable()->after('pin_recipient');
            $table->string('rider_notification_status', 20)->nullable()->after('tracking_recipient');
            $table->string('rider_notification_channel', 10)->nullable()->after('rider_notification_status');
            $table->timestamp('rider_notified_at')->nullable()->after('rider_notification_channel');

            $table->index(['passenger_id', 'is_third_party', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex(['passenger_id', 'is_third_party', 'status']);
            $table->dropColumn([
                'is_third_party',
                'rider_name',
                'rider_phone',
                'pin_recipient',
                'tracking_recipient',
                'rider_notification_status',
                'rider_notification_channel',
                'rider_notified_at',
            ]);
        });
    }
};
