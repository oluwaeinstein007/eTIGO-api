<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTipSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => AppSetting::getTipSettings(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'presets' => ['sometimes', 'array', 'min:1', 'max:10'],
            'presets.*' => ['integer', 'min:50', 'max:50000'],
            'min_amount' => ['sometimes', 'integer', 'min:1'],
            'max_amount' => ['sometimes', 'integer', 'min:100'],
        ]);

        if (isset($validated['min_amount'], $validated['max_amount'])
            && $validated['min_amount'] >= $validated['max_amount']) {
            return response()->json([
                'message' => 'min_amount must be less than max_amount.',
            ], 422);
        }

        DB::transaction(function () use ($validated) {
            if (isset($validated['presets'])) {
                $presets = array_map('intval', $validated['presets']);
                sort($presets);
                AppSetting::setValue('tip.presets', json_encode($presets), 'tip');
            }

            if (isset($validated['min_amount'])) {
                AppSetting::setValue('tip.min_amount', $validated['min_amount'], 'tip');
            }

            if (isset($validated['max_amount'])) {
                AppSetting::setValue('tip.max_amount', $validated['max_amount'], 'tip');
            }
        });

        return response()->json([
            'message' => 'Tip settings updated.',
            'settings' => AppSetting::getTipSettings(),
        ]);
    }
}
