<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_payment_methods', function (Blueprint $table) {
            $table->string('provider', 30)->nullable()->after('user_id')->index();
        });

        // Backfill existing payment methods based on token prefix
        DB::table('user_payment_methods')
            ->whereNull('provider')
            ->where('gateway_token', 'LIKE', 'FLW%')
            ->update(['provider' => 'flutterwave']);

        DB::table('user_payment_methods')
            ->whereNull('provider')
            ->where('gateway_token', 'NOT LIKE', 'FLW%')
            ->update(['provider' => 'paystack']);
    }

    public function down(): void
    {
        Schema::table('user_payment_methods', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropColumn('provider');
        });
    }
};
