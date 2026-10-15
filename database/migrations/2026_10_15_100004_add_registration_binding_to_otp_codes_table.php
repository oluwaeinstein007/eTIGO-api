<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A verified login code proves ownership of a phone for one account type
     * and may be used to complete exactly one registration.
     */
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('verified_for_type')->nullable()->after('verified_at');
            $table->timestamp('consumed_at')->nullable()->after('verified_for_type');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn(['verified_for_type', 'consumed_at']);
        });
    }
};
