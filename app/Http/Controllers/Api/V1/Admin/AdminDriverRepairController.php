<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RepairRequestStatus;
use App\Enums\RepairServiceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverRepairResource;
use App\Models\DriverRepair;
use App\Services\DriverRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Operations queue for the repair and service requests drivers send from the
 * app: book the workshop visit, finish with a report, or cancel.
 */
class AdminDriverRepairController extends Controller
{
    private const TIMEZONE = 'Africa/Lagos';

    public function __construct(
        private readonly DriverRepairService $repairs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'type' => ['nullable', Rule::enum(RepairServiceType::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'vehicle_id' => ['nullable', 'uuid'],
        ]);

        $statuses = $this->statuses($validated['status'] ?? null);

        if ($statuses === null) {
            return response()->json([
                'message' => 'Unknown status.',
                'errors' => ['status' => ['Use in_progress, scheduled, completed or cancelled.']],
            ], 422);
        }

        $repairs = DriverRepair::query()
            ->with(['vehicle', 'driver.driver.vehicle', 'handledBy'])
            ->whereIn('status', $statuses)
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($validated['vehicle_id'] ?? null, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, self::TIMEZONE)->startOfDay()))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to, self::TIMEZONE)->endOfDay()))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $q->where(fn ($q) => $q
                    ->where('reference_number', 'ilike', $like)
                    ->orWhere('title', 'ilike', $like)
                    ->orWhereHas('vehicle', fn ($v) => $v->where('plate_number', 'ilike', $like))
                    ->orWhereHas('driver', fn ($d) => $d->where(fn ($d) => $d
                        ->where('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhere('phone', 'ilike', $like))));
            })
            // New requests first (oldest waiting longest), then booked visits
            // soonest first, then finished ones newest first.
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN created_at END ASC")
            ->orderByRaw("CASE WHEN status = 'scheduled' THEN scheduled_at END ASC")
            ->orderByDesc('updated_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'repairs' => DriverRepairResource::collection($repairs),
            'summary' => collect(RepairRequestStatus::cases())
                ->mapWithKeys(fn (RepairRequestStatus $status) => [$status->value => 0])
                ->merge(DriverRepair::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n))
                ->all(),
            'meta' => [
                'current_page' => $repairs->currentPage(),
                'last_page' => $repairs->lastPage(),
                'per_page' => $repairs->perPage(),
                'total' => $repairs->total(),
            ],
        ]);
    }

    public function show(DriverRepair $repair): JsonResponse
    {
        return response()->json([
            'repair' => new DriverRepairResource($repair->load(['vehicle', 'driver.driver.vehicle', 'handledBy'])),
        ]);
    }

    public function schedule(Request $request, DriverRepair $repair): JsonResponse
    {
        $validated = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'workshop_name' => ['required', 'string', 'max:150'],
            'workshop_address' => ['required', 'string', 'max:300'],
            'technician_name' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        // A few minutes of slack: the operator may take a moment to submit.
        if (Carbon::parse($validated['scheduled_at'])->lt(now()->subMinutes(5))) {
            return response()->json([
                'message' => 'The visit cannot be in the past.',
                'errors' => ['scheduled_at' => ['The visit cannot be in the past.']],
            ], 422);
        }

        return $this->act($repair, fn () => $this->repairs->schedule($repair, $request->user(), $validated), 'Workshop visit booked. The driver has been told.');
    }

    public function complete(Request $request, DriverRepair $repair): JsonResponse
    {
        $validated = $request->validate([
            // Shown to the driver: what was done and anything they should know.
            'summary' => ['required', 'string', 'min:5', 'max:2000'],
            'report' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        return $this->act($repair, fn () => $this->repairs->complete($repair, $request->user(), $validated['summary'], $request->file('report')), 'Request completed. The driver has been told.');
    }

    public function cancel(Request $request, DriverRepair $repair): JsonResponse
    {
        $validated = $request->validate([
            // Shown to the driver.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->act($repair, fn () => $this->repairs->cancel($repair, $request->user(), $validated['reason']), 'Request cancelled. The driver has been told.');
    }

    /**
     * Add a photo or document (a quote, a workshop report) at any stage.
     */
    public function storeAttachment(Request $request, DriverRepair $repair): JsonResponse
    {
        $validated = $request->validate([
            'attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'title' => ['nullable', 'string', 'max:100'],
        ]);

        $attachment = $this->repairs->storeAttachment($request->file('attachment'), $repair->driver_id, $validated['title'] ?? null, uploadedBy: 'operations');
        $repair->attachments = [...($repair->attachments ?? []), $attachment];
        $repair->save();

        return response()->json([
            'message' => 'Attachment added.',
            'repair' => new DriverRepairResource($repair->load(['vehicle', 'driver.driver.vehicle', 'handledBy'])),
            'attachment' => $attachment,
        ], 201);
    }

    private function act(DriverRepair $repair, \Closure $action, string $message): JsonResponse
    {
        try {
            $repair = $action();
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'VALIDATION_ERROR',
                'current_status' => $repair->fresh()?->status->value,
            ], 422);
        }

        return response()->json([
            'message' => $message,
            'repair' => new DriverRepairResource($repair->load(['vehicle', 'driver.driver.vehicle', 'handledBy'])),
        ]);
    }

    /**
     * Default: the open requests (new and booked).
     *
     * @return list<string>|null null when a status is not recognised
     */
    private function statuses(?string $filter): ?array
    {
        if ($filter === null || trim($filter) === '') {
            return [RepairRequestStatus::InProgress->value, RepairRequestStatus::Scheduled->value];
        }

        $statuses = array_values(array_filter(array_map('trim', explode(',', $filter))));

        foreach ($statuses as $status) {
            if (! RepairRequestStatus::tryFrom($status)) {
                return null;
            }
        }

        return $statuses;
    }
}
