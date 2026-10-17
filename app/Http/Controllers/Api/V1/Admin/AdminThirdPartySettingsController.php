<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ThirdPartyRecipient;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Operations settings for rides booked for someone else (Phase 2,
 * BE2-3P-05). Changes apply to new bookings only: each ride keeps the PIN
 * and tracking recipients it was booked with.
 */
class AdminThirdPartySettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => AppSetting::thirdParty(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'pin_recipient' => ['sometimes', 'string', Rule::enum(ThirdPartyRecipient::class)],
            'tracking_recipient' => ['sometimes', 'string', Rule::enum(ThirdPartyRecipient::class)],
            'allow_cash' => ['sometimes', 'boolean'],
            'allow_bank_transfer' => ['sometimes', 'boolean'],
            'max_active_per_booker' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'max_rider_numbers_per_day' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'rider_trip_updates' => ['sometimes', 'boolean'],
        ]);

        $current = AppSetting::thirdParty();

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record(
                $request->user(),
                'third_party_settings.updated',
                $request->user(),
                array_intersect_key($current, $validated),
                $validated,
            );

            foreach ($validated as $key => $value) {
                AppSetting::setValue(
                    "third_party.{$key}",
                    is_bool($value) ? ($value ? 'true' : 'false') : $value,
                    'third_party',
                );
            }
        });

        return response()->json([
            'message' => 'Third-party booking settings updated. They apply to new bookings.',
            'settings' => AppSetting::thirdParty(),
        ]);
    }
}
