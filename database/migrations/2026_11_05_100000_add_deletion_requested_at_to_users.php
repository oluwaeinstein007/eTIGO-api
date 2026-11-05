<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the user asked for their account to be deleted, and the date it
     * will be erased (fixed at request time, so changing the grace period
     * never shortens a promise already made). PurgeDeletedAccountsJob erases
     * the account after that date; logging in again clears both.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('deletion_scheduled_for')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_for']);
        });
    }
};
