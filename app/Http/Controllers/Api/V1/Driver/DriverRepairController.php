<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\RepairRequestStatus;
use App\Enums\RepairServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreDriverRepairAttachmentRequest;
use App\Http\Requests\Driver\StoreDriverRepairFormRequest;
use App\Http\Resources\Concerns\ResolvesUploadUrls;
use App\Http\Resources\DriverRepairResource;
use App\Models\DriverRepair;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DriverRepairController extends Controller
{
    use ResolvesUploadUrls;

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

        $attachments = [];
        $file = $request->file('attachment') ?? $request->file('photo');
        if ($file instanceof UploadedFile) {
            $attachments[] = $this->processAttachmentUpload($file, $user->id);
        }

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
            'attachments' => $attachments,
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

    public function storeAttachment(DriverRepair $repair, StoreDriverRepairAttachmentRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($repair->driver_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $file = $request->file('attachment') ?? $request->file('photo');
        $customTitle = $request->input('title');

        $newAttachment = $this->processAttachmentUpload($file, $user->id, $customTitle);
        $attachments = $repair->attachments ?? [];
        $attachments[] = $newAttachment;
        $repair->attachments = $attachments;
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

    private function processAttachmentUpload(UploadedFile $file, string $driverId, ?string $customTitle = null): array
    {
        $path = $file->store("repairs/{$driverId}", config('filesystems.uploads'));
        $extension = strtolower($file->getClientOriginalExtension());
        $isPdf = $extension === 'pdf';
        $fileType = strtoupper($extension ?: ($isPdf ? 'PDF' : 'JPG'));
        $originalName = $file->getClientOriginalName();
        $title = $customTitle ?: ($isPdf ? (pathinfo($originalName, PATHINFO_FILENAME) ?: 'Document') : ($originalName ?: 'Photo attachment'));
        $subtitle = $isPdf ? 'PDF · Document attached' : "{$fileType} · Image attached";

        return [
            'id' => (string) Str::uuid(),
            'title' => $title,
            'file_type' => $fileType,
            'subtitle' => $subtitle,
            'path' => $path,
            'file_url' => $this->resolveUploadUrl($path),
            'created_at' => now()->toIso8601String(),
        ];
    }
}
