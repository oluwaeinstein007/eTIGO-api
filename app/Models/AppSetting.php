<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'group'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);

        return $setting ? $setting->value : $default;
    }

    public static function setValue(string $key, mixed $value, string $group = 'general'): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'group' => $group],
        );
    }

    public static function getTipSettings(): array
    {
        $settings = static::where('group', 'tip')->pluck('value', 'key');

        return [
            'presets' => json_decode($settings['tip.presets'] ?? json_encode([100, 200, 500, 1000]), true),
            'min_amount' => (int) ($settings['tip.min_amount'] ?? 50),
            'max_amount' => (int) ($settings['tip.max_amount'] ?? 50000),
            'currency' => config('wallet.currency', 'NGN'),
        ];
    }

    /**
     * Read one wallet setting, honouring the admin's runtime override
     * (PUT /admin/settings/wallet) before the config/wallet.php default.
     */
    public static function wallet(string $key): mixed
    {
        return static::getWalletSettings()[$key] ?? config("wallet.{$key}");
    }

    /**
     * @return array{min_topup: int, max_balance: int, daily_topup_cap: int, min_payout: int, settlement_delay: string, max_negative_balance: int, hold_expiry_hours: int, abandoned_topup_minutes: int, payout_account_hold_hours: int, payout_account_hold_first_account: bool, payout_account_name_check: string}
     */
    public static function getWalletSettings(): array
    {
        $settings = static::where('group', 'wallet')->pluck('value', 'key');

        return [
            'min_topup' => (int) ($settings['wallet.min_topup'] ?? config('wallet.min_topup')),
            'max_balance' => (int) ($settings['wallet.max_balance'] ?? config('wallet.max_balance')),
            'daily_topup_cap' => (int) ($settings['wallet.daily_topup_cap'] ?? config('wallet.daily_topup_cap')),
            'min_payout' => (int) ($settings['wallet.min_payout'] ?? config('wallet.min_payout')),
            'settlement_delay' => (string) ($settings['wallet.settlement_delay'] ?? config('wallet.settlement_delay', 'instant')),
            'max_negative_balance' => (int) ($settings['wallet.max_negative_balance'] ?? config('wallet.max_negative_balance')),
            'hold_expiry_hours' => (int) ($settings['wallet.hold_expiry_hours'] ?? config('wallet.hold_expiry_hours')),
            'abandoned_topup_minutes' => (int) ($settings['wallet.abandoned_topup_minutes'] ?? config('wallet.abandoned_topup_minutes')),
            'payout_account_hold_hours' => (int) ($settings['wallet.payout_account_hold_hours'] ?? config('wallet.payout_account_hold_hours')),
            'payout_account_hold_first_account' => filter_var($settings['wallet.payout_account_hold_first_account'] ?? config('wallet.payout_account_hold_first_account'), FILTER_VALIDATE_BOOLEAN),
            'payout_account_name_check' => (string) ($settings['wallet.payout_account_name_check'] ?? config('wallet.payout_account_name_check')),
        ];
    }

    /**
     * Scheduled-ride settings: the admin's runtime overrides
     * (PUT /admin/settings/scheduled-rides) over config/scheduled_rides.php.
     *
     * @return array{enabled: bool, min_lead_minutes: int, max_advance_days: int, dispatch_lead_minutes: int, reminder_minutes: int, matching_grace_minutes: int, retry_wave_delay_seconds: int, max_upcoming_per_passenger: int, min_minutes_between_bookings: int}
     */
    public static function scheduledRides(): array
    {
        $settings = static::where('group', 'scheduled_rides')->pluck('value', 'key');
        $value = fn (string $key) => $settings["scheduled_rides.{$key}"] ?? config("scheduled_rides.{$key}");

        return [
            'enabled' => filter_var($value('enabled'), FILTER_VALIDATE_BOOLEAN),
            'min_lead_minutes' => (int) $value('min_lead_minutes'),
            'max_advance_days' => (int) $value('max_advance_days'),
            'dispatch_lead_minutes' => (int) $value('dispatch_lead_minutes'),
            'reminder_minutes' => (int) $value('reminder_minutes'),
            'matching_grace_minutes' => (int) $value('matching_grace_minutes'),
            'retry_wave_delay_seconds' => (int) $value('retry_wave_delay_seconds'),
            'max_upcoming_per_passenger' => (int) $value('max_upcoming_per_passenger'),
            'min_minutes_between_bookings' => (int) $value('min_minutes_between_bookings'),
        ];
    }
}
