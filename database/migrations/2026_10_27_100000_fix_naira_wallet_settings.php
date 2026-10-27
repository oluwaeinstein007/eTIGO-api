<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An early DemoSeeder wrote the wallet settings in naira, though they are read
 * in kobo (₦5,000 max balance, ₦1,000 daily cap, ₦5 minimum top-up). The
 * seeder was corrected, but it skips app_settings that already exist, so
 * databases seeded then (staging) kept the old rows. Rows still holding the
 * old seeded value are set to the config/wallet.php defaults; values an admin
 * has changed since are left alone.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> key => [old seeded value, correct value] */
    private const FIXES = [
        'wallet.min_topup' => ['500', '50000'],                // ₦500
        'wallet.max_balance' => ['500000', '50000000'],        // ₦500,000
        'wallet.daily_topup_cap' => ['100000', '10000000'],    // ₦100,000
        'wallet.min_payout' => ['1000', '100000'],             // ₦1,000
        'wallet.settlement_delay' => ['24', 'instant'],        // not a valid value
        'wallet.max_negative_balance' => ['0', '-500000'],     // -₦5,000
        'wallet.hold_expiry_hours' => ['72', '4'],             // over the admin limit of 48
    ];

    public function up(): void
    {
        // Only a database carrying the old seed: at least three rows still hold
        // their old value, so an admin having changed one or two of them since
        // does not hide it, and one value that happens to match does not count.
        $oldRows = DB::table('app_settings')
            ->where(function ($q) {
                foreach (self::FIXES as $key => [$old]) {
                    $q->orWhere(fn ($q) => $q->where('key', $key)->where('value', $old));
                }
            })
            ->count();

        if ($oldRows < 3) {
            return;
        }

        foreach (self::FIXES as $key => [$old, $new]) {
            DB::table('app_settings')
                ->where('key', $key)
                ->where('value', $old)
                ->update(['value' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The old values were wrong; nothing to restore.
    }
};
