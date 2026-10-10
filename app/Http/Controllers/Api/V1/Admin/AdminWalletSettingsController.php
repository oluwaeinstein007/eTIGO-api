<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWalletSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = AppSetting::getWalletSettings();

        return response()->json([
            'settings' => [
                'currency' => config('wallet.currency', 'NGN'),
                'min_topup' => $settings['min_topup'],
                'max_balance' => $settings['max_balance'],
                'daily_topup_cap' => $settings['daily_topup_cap'],
                'min_payout' => $settings['min_payout'],
                'settlement_delay' => $settings['settlement_delay'],
                'max_negative_balance' => $settings['max_negative_balance'],
                'hold_expiry_hours' => $settings['hold_expiry_hours'],
                'abandoned_topup_minutes' => $settings['abandoned_topup_minutes'],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'min_topup' => ['sometimes', 'integer', 'min:1000'],
            'max_balance' => ['sometimes', 'integer', 'min:100000'],
            'daily_topup_cap' => ['sometimes', 'integer', 'min:100000'],
            'min_payout' => ['sometimes', 'integer', 'min:10000'],
            'settlement_delay' => ['sometimes', 'string', 'in:instant,24h,weekly'],
            'max_negative_balance' => ['sometimes', 'integer', 'max:0'],
            'hold_expiry_hours' => ['sometimes', 'integer', 'min:1', 'max:48'],
            'abandoned_topup_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated as $key => $value) {
                AppSetting::setValue("wallet.{$key}", $value, 'wallet');
            }
        });

        $settings = AppSetting::getWalletSettings();

        return response()->json([
            'message' => 'Wallet settings updated.',
            'settings' => $settings,
        ]);
    }
}
