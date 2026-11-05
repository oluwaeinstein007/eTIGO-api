<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Accounts opened as +2340803… (the local 0 kept after the country code)
     * become +234803…, the form sign-in now normalises every number to, so
     * their owners can still sign in. One whose fixed number already has an
     * account of the same type is left alone.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereRaw('phone ~ ?', ['^\+2340[0-9]{10}$'])
            ->orderBy('id')
            ->get(['id', 'phone', 'type'])
            ->each(function (object $user) {
                $phone = '+234'.substr($user->phone, 5);

                if (DB::table('users')->where('phone', $phone)->where('type', $user->type)->exists()) {
                    return;
                }

                DB::table('users')->where('id', $user->id)->update(['phone' => $phone]);
            });
    }

    public function down(): void
    {
        // The original form cannot be told apart once fixed.
    }
};
