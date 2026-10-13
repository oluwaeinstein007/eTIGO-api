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
        ]);
    }
}
