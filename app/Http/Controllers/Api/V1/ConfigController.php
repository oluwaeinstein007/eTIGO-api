<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LostItemCategory;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'min_app_version' => [
                'ios' => AppSetting::getValue('general.min_app_version_ios', '1.0.0'),
                'android' => AppSetting::getValue('general.min_app_version_android', '1.0.0'),
            ],
            'force_update' => filter_var(
                AppSetting::getValue('general.force_update', 'false'),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'emergency_number' => (string) config('sos.emergency_number', '112'),
            'support_phone' => AppSetting::getValue('general.support_phone'),
            'support_email' => AppSetting::getValue('general.support_email'),
            'scheduled_rides' => self::scheduledRideBookingRules(),
            'third_party_bookings' => self::thirdPartyBookingRules(),
            'lost_and_found' => self::lostAndFoundRules(),
        ]);
    }

    /**
     * What the "report a lost item" screen needs: whether it is on, how long
     * after a trip it can be used, the categories and the return fee.
     *
     * @return array{enabled: bool, report_window_days: int, return_fee: float, categories: list<array{value: string, label: string}>}
     */
    private static function lostAndFoundRules(): array
    {
        $settings = AppSetting::lostAndFound();

        return [
            'enabled' => $settings['enabled'],
            'report_window_days' => $settings['report_window_days'],
            'return_fee' => $settings['return_fee'],
            'categories' => array_map(
                fn (LostItemCategory $category) => ['value' => $category->value, 'label' => $category->label()],
                LostItemCategory::cases(),
            ),
        ];
    }

    /**
     * What the "book for someone else" screen needs: whether it is on, how
     * the booker may pay, and who will get the PIN and tracking.
     *
     * @return array{enabled: bool, payment_methods: list<string>, pin_recipient: string, tracking_recipient: string, max_active_per_booker: int}
     */
    private static function thirdPartyBookingRules(): array
    {
        $settings = AppSetting::thirdParty();

        return [
            'enabled' => $settings['enabled'],
            'payment_methods' => array_values(array_filter([
                PaymentMethod::Wallet->value,
                PaymentMethod::Card->value,
                $settings['allow_cash'] ? PaymentMethod::Cash->value : null,
                $settings['allow_bank_transfer'] ? PaymentMethod::BankTransfer->value : null,
            ])),
            'pin_recipient' => $settings['pin_recipient'],
            'tracking_recipient' => $settings['tracking_recipient'],
            'max_active_per_booker' => $settings['max_active_per_booker'],
        ];
    }

    /**
     * What the booking screen needs to offer a valid pickup time.
     *
     * @return array{enabled: bool, min_lead_minutes: int, max_advance_days: int, dispatch_lead_minutes: int, reservation_fee: float, late_cancellation_fee_basis: string, late_cancellation_fee: float, late_cancellation_window_minutes: int, driver_reservations_enabled: bool}
     */
    private static function scheduledRideBookingRules(): array
    {
        $settings = AppSetting::scheduledRides();

        return [
            'enabled' => $settings['enabled'],
            'min_lead_minutes' => $settings['min_lead_minutes'],
            'max_advance_days' => $settings['max_advance_days'],
            'dispatch_lead_minutes' => $settings['dispatch_lead_minutes'],
            'reservation_fee' => $settings['reservation_fee'],
            // economy_base_fare (the city's economy base fare), flat
            // (late_cancellation_fee) or off. The ride shows the exact amount
            // as scheduled.cancellation_fee_if_cancelled_now.
            'late_cancellation_fee_basis' => $settings['late_cancellation_fee_basis'],
            'late_cancellation_fee' => $settings['late_cancellation_fee'],
            'late_cancellation_window_minutes' => $settings['late_cancellation_window_minutes'],
            'driver_reservations_enabled' => $settings['driver_reservations_enabled'],
        ];
    }
}
