<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_remittances', function (Blueprint $table) {
            $table->text('excused_reason')->nullable()->after('settled');
        });

        Schema::table('fleet_agreements', function (Blueprint $table) {
            $table->string('shortfall_flag')->nullable()->after('shortfall_streak_days');
            $table->timestamp('shortfall_flagged_at')->nullable()->after('shortfall_flag');
            $table->string('vehicle_return_status')->nullable()->after('terminated_reason');
            $table->decimal('outstanding_amount', 14, 2)->default(0)->after('vehicle_return_status');
            $table->decimal('settlement_amount', 14, 2)->default(0)->after('outstanding_amount');
            $table->text('settlement_notes')->nullable()->after('settlement_amount');
            $table->timestamp('settled_at')->nullable()->after('settlement_notes');
        });
    }

    public function down(): void
    {
        Schema::table('daily_remittances', function (Blueprint $table) {
            $table->dropColumn('excused_reason');
        });

        Schema::table('fleet_agreements', function (Blueprint $table) {
            $table->dropColumn([
                'shortfall_flag',
                'shortfall_flagged_at',
                'vehicle_return_status',
                'outstanding_amount',
                'settlement_amount',
                'settlement_notes',
                'settled_at',
            ]);
        });
    }
};
