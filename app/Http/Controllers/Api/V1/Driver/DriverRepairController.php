<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\RepairRequestStatus;
use App\Enums\RepairServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreDriverRepairFormRequest;
use App\Http\Resources\DriverRepairResource;
use App\Models\DriverRepair;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverRepairController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $repairs = DriverRepair::query()
            ->forDriver($user->id)
            ->with(['vehicle', 'driver.driver.vehicle'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'repairs' => DriverRepairResource::collection($repairs),
        ]);
    }

    public function store(StoreDriverRepairFormRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $vehicle = $user->driver?->vehicle;

        $type = RepairServiceType::from($validated['type']);
        $preferredTime = $validated['preferred_time'];
        $preferredDate = $validated['preferred_date'];

        $repair = DriverRepair::create([
            'driver_id' => $user->id,
            'vehicle_id' => $vehicle?->id,
            'reference_number' => DriverRepair::generateReferenceNumber(),
            'type' => $type,
            'status' => RepairRequestStatus::InProgress,
            'title' => $validated['title'],
            'details' => $validated['details'],
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
            'summary_note' => "Request received · Workshop visit to be scheduled for {$preferredTime}",
            'workshop_visit' => [
                'scheduled_time' => $preferredTime,
                'instructions' => 'A technician is being assigned for your vehicle. Contact fleet support for workshop details.',
                'support_phone' => '0812 5690 6790',
            ],
            'progress_steps' => [
                [
                    'title' => 'Request submitted',
                    'subtitle' => now()->format('j M Y')." · {$type->label()} received",
                    'state' => 'completed',
                ],
                [
                    'title' => 'Technician assigned',
                    'subtitle' => 'Your request is being coordinated',
                    'state' => 'in_progress',
                ],
                [
                    'title' => 'Workshop visit',
                    'subtitle' => "Scheduled for {$preferredTime}",
                    'state' => 'upcoming',
                ],
                [
                    'title' => 'Inspection complete',
                    'subtitle' => 'Report will be available once completed',
                    'state' => 'upcoming',
                ],
            ],
            'attachments' => [],
        ]);

        $repair->load(['vehicle', 'driver.driver.vehicle']);

        return response()->json([
            'message' => 'Vehicle service request submitted successfully.',
            'repair' => new DriverRepairResource($repair),
        ], 201);
    }

    public function show(DriverRepair $repair, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($repair->driver_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $repair->load(['vehicle', 'driver.driver.vehicle']);

        return response()->json([
            'repair' => new DriverRepairResource($repair),
        ]);
    }
}
