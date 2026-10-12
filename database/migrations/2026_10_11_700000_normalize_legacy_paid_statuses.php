<?php

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rides')
            ->whereIn('payment_status', ['paid', 'successful'])
            ->update(['payment_status' => PaymentStatus::Settled->value]);

        DB::table('payments')
            ->whereIn('status', ['paid', 'successful'])
            ->update(['status' => PaymentStatus::Settled->value]);
    }

    public function down(): void
    {
        // Irreversible normalization of legacy status strings
    }
};
