<?php

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payments')
            ->where('status', 'successful')
            ->update(['status' => PaymentStatus::Settled->value]);
    }

    public function down(): void
    {
        // Irreversible normalization of invalid status string
    }
};
