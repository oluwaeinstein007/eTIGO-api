<?php

namespace App\Http\Controllers\Api\V1;

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
            'emergency_number' => '112',
            'support_phone' => AppSetting::getValue('general.support_phone'),
            'support_email' => AppSetting::getValue('general.support_email'),
            'scheduled_rides' => self::scheduledRideBookingRules(),
        ]);
    }

    /**
     * What the booking screen needs to offer a valid pickup time.
     *
     * @return array{enabled: bool, min_lead_minutes: int, max_advance_days: int, dispatch_lead_minutes: int}
     */
    private static function scheduledRideBookingRules(): array
    {
        $settings = AppSetting::scheduledRides();

        return [
            'enabled' => $settings['enabled'],
            'min_lead_minutes' => $settings['min_lead_minutes'],
            'max_advance_days' => $settings['max_advance_days'],
            'dispatch_lead_minutes' => $settings['dispatch_lead_minutes'],
        ];
    }
}
