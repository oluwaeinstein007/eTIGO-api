<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const TRUNK_ZERO = '^\+2340[0-9]{10}$';

    /**
     * Accounts opened as +2340803… (the local 0 kept after the country code)
     * become +234803…, the form sign-in now normalises every number to, so
     * their owners can still sign in. One whose fixed number already has an
     * account of the same type is left alone and logged for support to merge
     * or retire by hand, since the two accounts may hold different rides or
     * money. Unused sign-in codes move to the new form too, so a phone
     * verified just before the deploy can still finish registering.
     */
    public function up(): void
    {
        // No registration may take a fixed number between the check and the update.
        DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');

        DB::table('users')
            ->select(['id', 'phone', 'type'])
            ->whereRaw('phone ~ ?', [self::TRUNK_ZERO])
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    $phone = $this->fixed($user->phone);

                    if (DB::table('users')->where('phone', $phone)->where('type', $user->type)->exists()) {
                        Log::warning('Phone not normalised: another account of the same type has the fixed number', [
                            'user_id' => $user->id,
                            'type' => $user->type,
                        ]);

                        continue;
                    }

                    DB::table('users')->where('id', $user->id)->update(['phone' => $phone]);
                }
            });

        DB::table('otp_codes')
            ->select(['id', 'phone'])
            ->whereRaw('phone ~ ?', [self::TRUNK_ZERO])
            ->whereNull('consumed_at')
            ->chunkById(500, function ($codes) {
                foreach ($codes as $code) {
                    DB::table('otp_codes')->where('id', $code->id)->update(['phone' => $this->fixed($code->phone)]);
                }
            });
    }

    public function down(): void
    {
        // The original form cannot be told apart once fixed.
    }

    private function fixed(string $phone): string
    {
        return '+234'.substr($phone, 5);
    }
};
