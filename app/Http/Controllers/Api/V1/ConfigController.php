<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LostItemCategory;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\ThirdPartyBookingService;
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
            // How passengers may pay for a booking: hide cash when it is off.
            'payments' => [
                'cash_enabled' => AppSetting::cashPaymentsEnabled(),
                'methods' => array_values(array_filter([
                    PaymentMethod::Card->value,
                    PaymentMethod::Wallet->value,
                    AppSetting::cashPaymentsEnabled() ? PaymentMethod::Cash->value : null,
                ])),
            ],
            'scheduled_rides' => self::scheduledRideBookingRules(),
            'third_party_bookings' => self::thirdPartyBookingRules(),
            'lost_and_found' => self::lostAndFoundRules(),
            'ev_charging' => self::evChargingRules(),
            'vehicle_maintenance' => self::vehicleMaintenanceRules(),
        ]);
    }

    /**
     * The EV charging numbers the driver app shows in copy ("₦500 reservation
     * fee", "check in within 30 minutes", "cancel free for 2 minutes"), so
     * they are never hard-coded. A driver's own fee (waived or not) is in
     * GET /driver/vehicle/status as charging.reservation_fee.
     *
     * @return array{reservation_fee: float, reservation_fee_kobo: int, check_in_minutes: int, free_cancellation_minutes: int, max_queue_size: int, retry_delay_minutes: int, hire_purchase_fee_waiver: bool}
     */
    private static function evChargingRules(): array
    {
        $settings = AppSetting::evCharging();

        return [
            'reservation_fee' => $settings['reservation_fee'],
            'reservation_fee_kobo' => (int) round($settings['reservation_fee'] * 100),
            'check_in_minutes' => $settings['reservation_expiry_minutes'],
            'free_cancellation_minutes' => $settings['free_cancellation_minutes'],
            'max_queue_size' => $settings['max_queue_size'],
            'retry_delay_minutes' => $settings['retry_delay_minutes'],
            'hire_purchase_fee_waiver' => $settings['hire_purchase_fee_waiver'],
        ];
    }

    /**
     * The vehicle maintenance numbers for copy ("papers expiring within 14
     * days", "7 days to upload a renewal"). Each item also carries a ready
     * message from GET /driver/vehicle/status.
     *
     * @return array{enabled: bool, due_soon_days: int, due_soon_km: int, legal_grace_days: int, block_on_expired_papers: bool}
     */
    private static function vehicleMaintenanceRules(): array
    {
        $settings = AppSetting::vehicleMaintenance();

        return [
            'enabled' => $settings['enabled'],
            'due_soon_days' => $settings['due_soon_days'],
            'due_soon_km' => $settings['due_soon_km'],
            'legal_grace_days' => $settings['legal_grace_days'],
            'block_on_expired_papers' => $settings['block_on_expired_papers'],
        ];
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
     * share_with_options are the "Who gets the PIN?" choices for POST /rides,
     * with the label and description to show (empty: hide the question);
     * default_share_with is the one to pre-select.
     *
     * @return array{enabled: bool, payment_methods: list<string>, pin_recipient: string, tracking_recipient: string, share_with_options: list<array{value: string, label: string, description: string}>, default_share_with: string|null, max_active_per_booker: int}
     */
    private static function thirdPartyBookingRules(): array
    {
        $settings = AppSetting::thirdParty();

        return [
            'enabled' => $settings['enabled'],
            'payment_methods' => array_values(array_filter([
                PaymentMethod::Wallet->value,
                PaymentMethod::Card->value,
                $settings['allow_cash'] && AppSetting::cashPaymentsEnabled() ? PaymentMethod::Cash->value : null,
                $settings['allow_bank_transfer'] ? PaymentMethod::BankTransfer->value : null,
            ])),
            'pin_recipient' => $settings['pin_recipient'],
            'tracking_recipient' => $settings['tracking_recipient'],
            'share_with_options' => ThirdPartyBookingService::shareWithChoices($settings),
            'default_share_with' => ThirdPartyBookingService::defaultShareWith($settings),
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
