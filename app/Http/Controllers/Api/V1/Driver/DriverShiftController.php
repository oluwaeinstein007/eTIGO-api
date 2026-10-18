<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\DriverShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverShiftResource;
use App\Services\DriverShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A shift driver's timetable on a shared fleet car (OQ-20). Drivers of
 * their own car or a car not in shift mode have no shifts.
 */
class DriverShiftController extends Controller
{
    public function __construct(
        private DriverShiftService $shifts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['include_past' => ['sometimes', 'boolean']]);

        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $settings = $this->shifts->settings();

        $shifts = $driver->shifts()
            ->with('vehicle')
            ->when(
                $request->boolean('include_past'),
                fn ($q) => $q->where('ends_at', '>', now()->subDays(30)),
                fn ($q) => $q->where('ends_at', '>', now())->where('status', '!=', DriverShiftStatus::Cancelled),
            )
            ->orderBy('starts_at')
            ->paginate(30);

        $current = $this->shifts->openShift($driver);

        return response()->json([
            'shift_driver' => $this->shifts->isShiftDriver($driver),
            'current_shift' => $current ? new DriverShiftResource($current->load('vehicle')) : null,
            'rules' => [
                'online_lead_minutes' => $settings['shift_online_lead_minutes'],
                'offer_cutoff_minutes' => $settings['shift_offer_cutoff_minutes'],
                'missed_after_minutes' => $settings['shift_missed_after_minutes'],
            ],
            'shifts' => DriverShiftResource::collection($shifts),
            'meta' => [
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
    }
}
