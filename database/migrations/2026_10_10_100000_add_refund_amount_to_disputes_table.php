<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->decimal('refund_amount', 10, 2)->nullable()->after('resolution_notes');
            $table->string('refund_currency', 3)->nullable()->after('refund_amount');
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropColumn(['refund_amount', 'refund_currency']);
        });
    }
};
