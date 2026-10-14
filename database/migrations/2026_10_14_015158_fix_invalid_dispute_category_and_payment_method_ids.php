<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $validCategories = [
            'fare_dispute', 'driver_behaviour', 'route_deviation',
            'vehicle_condition', 'safety_concern', 'payment_issue',
            'item_left_behind', 'other',
        ];

        DB::table('disputes')
            ->whereNotIn('category', $validCategories)
            ->update(['category' => 'other']);

        DB::table('payments')
            ->whereNotNull('gateway_payment_method_id')
            ->whereRaw("gateway_payment_method_id !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'")
            ->update(['gateway_payment_method_id' => null]);
    }

    public function down(): void {}
};
