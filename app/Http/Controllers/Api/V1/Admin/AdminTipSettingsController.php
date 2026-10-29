<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminTipSettingsController extends Controller
{
    /**
     * Fixed bounds the settings must stay inside, so the form can check them
     * before saving. Amounts are in naira.
     */
    private const LIMITS = [
        'min_amount_floor' => 1,
        'max_amount_floor' => 100,
        'preset_min' => 50,
        'preset_max' => 50000,
        'presets_max_count' => 10,
    ];

    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => AppSetting::getTipSettings(),
            'limits' => self::LIMITS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'presets' => ['sometimes', 'array', 'min:1', 'max:'.self::LIMITS['presets_max_count']],
            'presets.*' => ['integer', 'distinct', 'min:'.self::LIMITS['preset_min'], 'max:'.self::LIMITS['preset_max']],
            'min_amount' => ['sometimes', 'integer', 'min:'.self::LIMITS['min_amount_floor']],
            'max_amount' => ['sometimes', 'integer', 'min:'.self::LIMITS['max_amount_floor']],
        ]);

        $current = AppSetting::getTipSettings();
        $minAmount = $validated['min_amount'] ?? $current['min_amount'];
        $maxAmount = $validated['max_amount'] ?? $current['max_amount'];

        // Errors go on the field that was sent, so the form can show them
        // beside it; a field left out keeps its saved value.
        if ($minAmount >= $maxAmount) {
            $field = array_key_exists('min_amount', $validated) ? 'min_amount' : 'max_amount';

            throw ValidationException::withMessages([
                $field => $field === 'min_amount'
                    ? "The minimum tip must be less than the maximum tip (₦{$maxAmount})."
                    : "The maximum tip must be more than the minimum tip (₦{$minAmount}).",
            ]);
        }

        $presets = $validated['presets'] ?? $current['presets'];
        $errors = [];

        foreach ($presets as $index => $preset) {
            if ($preset >= $minAmount && $preset <= $maxAmount) {
                continue;
            }

            if (array_key_exists('presets', $validated)) {
                $errors["presets.{$index}"] = "Each tip preset must be between ₦{$minAmount} and ₦{$maxAmount}.";
            } else {
                $field = $preset < $minAmount ? 'min_amount' : 'max_amount';
                $errors[$field] = "The saved preset ₦{$preset} would fall outside ₦{$minAmount}–₦{$maxAmount}. Change the presets too.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record(
                $request->user(),
                'tip_settings.updated',
                $request->user(),
                array_intersect_key($current, $validated),
                $validated,
            );

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
