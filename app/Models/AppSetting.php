<?php

namespace App\Models;

use App\Enums\ThirdPartyRecipient;
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
     * @return array{min_topup: int, max_balance: int, daily_topup_cap: int, card_verification_amount: int, min_payout: int, settlement_delay: string, max_negative_balance: int, hold_expiry_hours: int, abandoned_topup_minutes: int, payout_account_hold_hours: int, payout_account_hold_first_account: bool, payout_account_name_check: string, block_booking_with_balance_due: bool}
     */
    public static function getWalletSettings(): array
    {
        $settings = static::where('group', 'wallet')->pluck('value', 'key');

        return [
            'min_topup' => (int) ($settings['wallet.min_topup'] ?? config('wallet.min_topup')),
            'max_balance' => (int) ($settings['wallet.max_balance'] ?? config('wallet.max_balance')),
            'daily_topup_cap' => (int) ($settings['wallet.daily_topup_cap'] ?? config('wallet.daily_topup_cap')),
            'card_verification_amount' => (int) ($settings['wallet.card_verification_amount'] ?? config('wallet.card_verification_amount')),
            'min_payout' => (int) ($settings['wallet.min_payout'] ?? config('wallet.min_payout')),
            'settlement_delay' => (string) ($settings['wallet.settlement_delay'] ?? config('wallet.settlement_delay', 'instant')),
            'max_negative_balance' => (int) ($settings['wallet.max_negative_balance'] ?? config('wallet.max_negative_balance')),
            'hold_expiry_hours' => (int) ($settings['wallet.hold_expiry_hours'] ?? config('wallet.hold_expiry_hours')),
            'abandoned_topup_minutes' => (int) ($settings['wallet.abandoned_topup_minutes'] ?? config('wallet.abandoned_topup_minutes')),
            'payout_account_hold_hours' => (int) ($settings['wallet.payout_account_hold_hours'] ?? config('wallet.payout_account_hold_hours')),
            'payout_account_hold_first_account' => filter_var($settings['wallet.payout_account_hold_first_account'] ?? config('wallet.payout_account_hold_first_account'), FILTER_VALIDATE_BOOLEAN),
            'payout_account_name_check' => (string) ($settings['wallet.payout_account_name_check'] ?? config('wallet.payout_account_name_check')),
            'block_booking_with_balance_due' => filter_var($settings['wallet.block_booking_with_balance_due'] ?? config('wallet.block_booking_with_balance_due'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Scheduled-ride settings: the admin's runtime overrides
     * (PUT /admin/settings/scheduled-rides) over config/scheduled_rides.php.
     *
     * @return array{enabled: bool, min_lead_minutes: int, max_advance_days: int, dispatch_lead_minutes: int, reminder_minutes: int, matching_grace_minutes: int, retry_wave_delay_seconds: int, max_upcoming_per_passenger: int, min_minutes_between_bookings: int, driver_reservations_enabled: bool, max_reservations_per_driver: int, driver_reservation_gap_minutes: int, driver_reservation_buffer_minutes: int, reservation_fee: float, late_cancellation_fee_basis: string, late_cancellation_fee_vehicle_class: string, late_cancellation_fee: float, late_cancellation_window_minutes: int}
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
            'driver_reservations_enabled' => filter_var($value('driver_reservations_enabled'), FILTER_VALIDATE_BOOLEAN),
            'max_reservations_per_driver' => (int) $value('max_reservations_per_driver'),
            'driver_reservation_gap_minutes' => (int) $value('driver_reservation_gap_minutes'),
            'driver_reservation_buffer_minutes' => (int) $value('driver_reservation_buffer_minutes'),
            'reservation_fee' => round((float) $value('reservation_fee'), 2),
            'late_cancellation_fee_basis' => (string) $value('late_cancellation_fee_basis'),
            'late_cancellation_fee_vehicle_class' => (string) $value('late_cancellation_fee_vehicle_class'),
            'late_cancellation_fee' => round((float) $value('late_cancellation_fee'), 2),
            'late_cancellation_window_minutes' => (int) $value('late_cancellation_window_minutes'),
        ];
    }

    /**
     * Third-party booking policy (Phase 2, BE2-3P-02): the admin's runtime
     * overrides (PUT /admin/settings/third-party) over config/third_party.php.
     *
     * @return array{enabled: bool, pin_recipient: string, tracking_recipient: string, allow_cash: bool, allow_bank_transfer: bool, max_active_per_booker: int, max_rider_numbers_per_day: int, rider_trip_updates: bool}
     */
    public static function thirdParty(): array
    {
        $settings = static::where('group', 'third_party')->pluck('value', 'key');
        $value = fn (string $key) => $settings["third_party.{$key}"] ?? config("third_party.{$key}");

        return [
            'enabled' => filter_var($value('enabled'), FILTER_VALIDATE_BOOLEAN),
            'pin_recipient' => ThirdPartyRecipient::tryFrom((string) $value('pin_recipient'))?->value ?? ThirdPartyRecipient::Both->value,
            'tracking_recipient' => ThirdPartyRecipient::tryFrom((string) $value('tracking_recipient'))?->value ?? ThirdPartyRecipient::Both->value,
            'allow_cash' => filter_var($value('allow_cash'), FILTER_VALIDATE_BOOLEAN),
            'allow_bank_transfer' => filter_var($value('allow_bank_transfer'), FILTER_VALIDATE_BOOLEAN),
            'max_active_per_booker' => (int) $value('max_active_per_booker'),
            'max_rider_numbers_per_day' => (int) $value('max_rider_numbers_per_day'),
            'rider_trip_updates' => filter_var($value('rider_trip_updates'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Lost & Found settings (Phase 2): the admin's runtime overrides
     * (PUT /admin/settings/lost-and-found) over config/lost_and_found.php.
     *
     * @return array{enabled: bool, report_window_days: int, max_open_reports_per_passenger: int, driver_response_hours: int, return_deadline_days: int, auto_resolve_hours: int, denied_auto_close_days: int, share_contact_details: bool, return_fee: float}
     */
    public static function lostAndFound(): array
    {
        $settings = static::where('group', 'lost_and_found')->pluck('value', 'key');
        $value = fn (string $key) => $settings["lost_and_found.{$key}"] ?? config("lost_and_found.{$key}");

        return [
            'enabled' => filter_var($value('enabled'), FILTER_VALIDATE_BOOLEAN),
            'report_window_days' => (int) $value('report_window_days'),
            'max_open_reports_per_passenger' => (int) $value('max_open_reports_per_passenger'),
            'driver_response_hours' => (int) $value('driver_response_hours'),
            'return_deadline_days' => (int) $value('return_deadline_days'),
            'auto_resolve_hours' => (int) $value('auto_resolve_hours'),
            'denied_auto_close_days' => (int) $value('denied_auto_close_days'),
            'share_contact_details' => filter_var($value('share_contact_details'), FILTER_VALIDATE_BOOLEAN),
            'return_fee' => round(max(0.0, (float) $value('return_fee')), 2),
        ];
    }
}
