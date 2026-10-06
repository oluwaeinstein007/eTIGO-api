<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropUnique(['email']);

            $table->unique(['phone', 'type']);
            $table->unique(['email', 'type']);
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unique(['provider', 'provider_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email', 'type']);
            $table->dropUnique(['phone', 'type']);

            $table->unique(['phone']);
            $table->unique(['email']);
        });
    }
};
