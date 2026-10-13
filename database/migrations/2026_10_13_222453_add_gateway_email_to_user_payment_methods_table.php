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
            $table->string('gateway_email')->nullable()->after('gateway_token');
        });

        DB::table('user_payment_methods')
            ->whereNull('gateway_email')
            ->update([
                'gateway_email' => DB::raw('(SELECT email FROM users WHERE users.id = user_payment_methods.user_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('user_payment_methods', function (Blueprint $table) {
            $table->dropColumn('gateway_email');
        });
    }
};
