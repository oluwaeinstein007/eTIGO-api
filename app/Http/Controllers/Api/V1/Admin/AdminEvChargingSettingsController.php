<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operations settings for EV charging: the reservation fee and who is
 * spared it, how long a stall is held, the free-cancellation window and the
 * queue. They apply to reservations made or confirmed after the change; a
 * reservation keeps the fee it was given.
 */
class AdminEvChargingSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => AppSetting::evCharging(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Naira.
            'reservation_fee' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            // How long a held stall waits for the driver to start charging.
            'reservation_expiry_minutes' => ['sometimes', 'integer', 'min:5', 'max:240'],
            'free_cancellation_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'max_queue_size' => ['sometimes', 'integer', 'min:0', 'max:100'],
            // A full station takes a queue only when a stall frees up within this time.
            'imminent_departure_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'retry_delay_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'max_active_reservations_per_driver' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'hire_purchase_fee_waiver' => ['sometimes', 'boolean'],
            'waive_fee_while_agreement_paused' => ['sometimes', 'boolean'],
        ]);

        foreach (['hire_purchase_fee_waiver', 'waive_fee_while_agreement_paused'] as $flag) {
            if (array_key_exists($flag, $validated)) {
                $validated[$flag] = $request->boolean($flag);
            }
        }

        $current = AppSetting::evCharging();

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record(
                $request->user(),
                'ev_charging_settings.updated',
                $request->user(),
                array_intersect_key($current, $validated),
                $validated,
            );

            foreach ($validated as $key => $value) {
                AppSetting::setValue(
                    "ev_charging.{$key}",
                    is_bool($value) ? ($value ? 'true' : 'false') : $value,
                    'ev_charging',
                );
            }
        });

        return response()->json([
            'message' => 'EV charging settings updated.',
            'settings' => AppSetting::evCharging(),
        ]);
    }
}
