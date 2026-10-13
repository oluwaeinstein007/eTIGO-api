<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payments')
            ->whereNotNull('gateway_payment_method_id')
            ->orderBy('id')
            ->chunk(100, function ($payments) {
                foreach ($payments as $payment) {
                    if (! Str::isUuid($payment->gateway_payment_method_id)) {
                        $userPaymentMethodId = DB::table('rides')
                            ->join('user_payment_methods', 'user_payment_methods.user_id', '=', 'rides.passenger_id')
                            ->where('rides.id', $payment->ride_id)
                            ->where('user_payment_methods.is_default', true)
                            ->value('user_payment_methods.id');

                        if (! $userPaymentMethodId) {
                            $userPaymentMethodId = DB::table('rides')
                                ->join('user_payment_methods', 'user_payment_methods.user_id', '=', 'rides.passenger_id')
                                ->where('rides.id', $payment->ride_id)
                                ->value('user_payment_methods.id');
                        }

                        DB::table('payments')
                            ->where('id', $payment->id)
                            ->update(['gateway_payment_method_id' => $userPaymentMethodId]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data migration
    }
};
