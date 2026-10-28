<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreDriverRepairAttachmentRequest;
use App\Http\Requests\Driver\StoreDriverRepairFormRequest;
use App\Http\Resources\DriverRepairResource;
use App\Models\DriverRepair;
use App\Services\DriverRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DriverRepairController extends Controller
{
    public function __construct(
        private readonly DriverRepairService $repairs,
    ) {}

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
        $repair = $this->repairs->submit(
            $request->user(),
            $request->validated(),
            $request->file('attachment') ?? $request->file('photo'),
        );

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

    /**
     * The driver no longer needs the visit (fixed elsewhere, sold the car).
     */
    public function cancel(DriverRepair $repair, Request $request): JsonResponse
    {
        if ($repair->driver_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $repair = $this->repairs->cancel($repair, $request->user(), $validated['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $repair->load(['vehicle', 'driver.driver.vehicle']);

        return response()->json([
            'message' => 'Service request cancelled.',
            'repair' => new DriverRepairResource($repair),
        ]);
    }

    public function storeAttachment(DriverRepair $repair, StoreDriverRepairAttachmentRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($repair->driver_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $file = $request->file('attachment') ?? $request->file('photo');

        $newAttachment = $this->repairs->storeAttachment($file, $user->id, $request->input('title'));
        $repair->attachments = [...($repair->attachments ?? []), $newAttachment];
        $repair->save();

        $repair->load(['vehicle', 'driver.driver.vehicle']);

        return response()->json([
            'message' => 'Attachment added successfully.',
            'repair' => new DriverRepairResource($repair),
            'attachment' => $newAttachment,
        ], 201);
    }

    public function destroyAttachment(DriverRepair $repair, string $attachmentId, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($repair->driver_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $attachments = $repair->attachments ?? [];
        $index = null;
        $foundAttachment = null;

        foreach ($attachments as $i => $item) {
            if (($item['id'] ?? '') === $attachmentId) {
                $index = $i;
                $foundAttachment = $item;
                break;
            }
        }

        if ($index === null) {
            return response()->json([
                'message' => 'Attachment not found.',
            ], 404);
        }

        // A workshop report E-tiGo attached stays on the request.
        if (($foundAttachment['uploaded_by'] ?? 'driver') === 'operations') {
            return response()->json([
                'message' => 'Files added by E-tiGo cannot be removed.',
            ], 403);
        }

        if (! empty($foundAttachment['path'])) {
            try {
                Storage::disk(config('filesystems.uploads'))->delete($foundAttachment['path']);
            } catch (\Throwable) {
                // Ignore storage deletion errors
            }
        }

        array_splice($attachments, $index, 1);
        $repair->attachments = array_values($attachments);
        $repair->save();

        $repair->load(['vehicle', 'driver.driver.vehicle']);

        return response()->json([
            'message' => 'Attachment removed successfully.',
            'repair' => new DriverRepairResource($repair),
        ]);
    }
}
