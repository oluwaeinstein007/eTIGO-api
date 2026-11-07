<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Services\DriverDocumentComplianceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operations settings for driver document renewals: the reminder steps,
 * and the grace period before an expired driving licence keeps the driver
 * off the road. A changed grace period applies at the next daily check, or
 * when the driver next tries to go online.
 */
class AdminDriverDocumentSettingsController extends Controller
{
    /**
     * The driver document settings in force (overrides over config/driver_documents.php).
     */
    public function show(): JsonResponse
    {
        return response()->json(['settings' => AppSetting::driverDocuments()]);
    }

    /**
     * Change some settings; a new grace period or block switch rechecks drivers at once.
     */
    public function update(Request $request, DriverDocumentComplianceService $compliance): JsonResponse
    {
        $validated = $request->validate([
            'reminders_enabled' => ['sometimes', 'boolean'],
            'reminder_days' => ['sometimes', 'array', 'min:1', 'max:6'],
            'reminder_days.*' => ['integer', 'min:1', 'max:90', 'distinct'],
            'licence_grace_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'block_on_expired_licence' => ['sometimes', 'boolean'],
        ]);

        $current = AppSetting::driverDocuments();

        if (isset($validated['reminder_days'])) {
            $validated['reminder_days'] = array_values(array_map('intval', $validated['reminder_days']));
            rsort($validated['reminder_days']);
        }

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record($request->user(), 'driver_document_settings.updated', $request->user(), array_intersect_key($current, $validated), $validated);

            foreach ($validated as $key => $value) {
                AppSetting::setValue(
                    "driver_documents.{$key}",
                    match (true) {
                        is_bool($value) => $value ? 'true' : 'false',
                        is_array($value) => json_encode($value),
                        default => $value,
                    },
                    'driver_documents',
                );
            }
        });

        // A shorter grace period (or the block turned on or off) applies now.
        if (array_key_exists('licence_grace_days', $validated) || array_key_exists('block_on_expired_licence', $validated)) {
            $compliance->refreshDue();
        }

        return response()->json([
            'message' => 'Driver document settings updated.',
            'settings' => AppSetting::driverDocuments(),
        ]);
    }
}
