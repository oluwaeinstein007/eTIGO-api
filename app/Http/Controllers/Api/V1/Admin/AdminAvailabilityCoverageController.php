<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\DriverAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Planned drivers per hour and area in a city, from drivers' voluntary
 * availability plans (OQ-20), to spot gaps and target incentives.
 */
class AdminAvailabilityCoverageController extends Controller
{
    public function __invoke(Request $request, DriverAvailabilityService $availability): JsonResponse
    {
        $validated = $request->validate([
            'city_id' => ['required', 'uuid', Rule::exists('cities', 'id')],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after:from'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : now();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : $from->copy()->addDays(7);

        if ($from->diffInHours($to) > 14 * 24) {
            return response()->json([
                'message' => 'Coverage can be shown for up to 14 days at a time.',
                'errors' => ['to' => ['Coverage can be shown for up to 14 days at a time.']],
            ], 422);
        }

        return response()->json([
            'coverage' => $availability->coverage(City::findOrFail($validated['city_id']), $from, $to),
        ]);
    }
}
