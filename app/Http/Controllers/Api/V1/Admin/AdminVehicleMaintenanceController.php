<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\MaintenanceItemStatus;
use App\Enums\MaintenanceItemType;
use App\Enums\MaintenanceRecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\VehicleMaintenanceItemResource;
use App\Http\Resources\VehicleMaintenanceRecordResource;
use App\Http\Resources\VehicleResource;
use App\Models\AuditLog;
use App\Models\DriverDocument;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceItem;
use App\Models\VehicleMaintenanceRecord;
use App\Services\VehicleMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Operations view of vehicle maintenance (OQ-19): what is due across the
 * fleet, each car's items, records and papers; recording work on fleet
 * cars, approving drivers' proof for their own cars, uploading a fleet
 * car's papers, and linking papers the migration could not match to a car.
 */
class AdminVehicleMaintenanceController extends Controller
{
    public function __construct(
        private VehicleMaintenanceService $maintenance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'type' => ['sometimes', Rule::enum(MaintenanceItemType::class)],
            'fleet_only' => ['sometimes', 'boolean'],
            'blocked_only' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:50'],
        ]);

        $statuses = isset($validated['status'])
            ? array_values(array_filter(array_map(fn ($s) => MaintenanceItemStatus::tryFrom(trim($s)), explode(',', $validated['status']))))
            : [MaintenanceItemStatus::DueSoon, MaintenanceItemStatus::Overdue, MaintenanceItemStatus::Expired];

        $vehicleFilter = function ($q) use ($request, $validated) {
            $q->when($request->boolean('fleet_only'), fn ($v) => $v->where('is_fleet', true))
                ->when($request->boolean('blocked_only'), fn ($v) => $v->whereNotNull('blocked_at'))
                ->when($validated['search'] ?? null, function ($v, $search) {
                    $search = str_replace(['%', '_'], ['\%', '\_'], $search);
                    $v->where(fn ($s) => $s->where('plate_number', 'ilike', "%{$search}%")
                        ->orWhere('make', 'ilike', "%{$search}%")
                        ->orWhere('model', 'ilike', "%{$search}%"));
                });
        };

        $items = VehicleMaintenanceItem::query()
            ->current()
            ->whereIn('status', $statuses)
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->whereHas('vehicle', $vehicleFilter)
            ->with(['vehicle.driver.user'])
            ->orderByRaw("case status when 'expired' then 0 when 'overdue' then 1 when 'due_soon' then 2 when 'unknown' then 3 else 4 end")
            ->orderBy('due_at')
            ->paginate(30);

        return response()->json([
            'items' => $items->getCollection()->map(fn (VehicleMaintenanceItem $item) => [
                ...(new VehicleMaintenanceItemResource($item))->resolve($request),
                'vehicle' => [
                    'id' => $item->vehicle->id,
                    'plate_number' => $item->vehicle->plate_number,
                    'make' => $item->vehicle->make,
                    'model' => $item->vehicle->model,
                    'is_fleet' => (bool) $item->vehicle->is_fleet,
                    'is_blocked' => $item->vehicle->isBlocked(),
                    'driver' => $item->vehicle->driver ? [
                        'id' => $item->vehicle->driver->id,
                        'name' => trim(($item->vehicle->driver->user?->first_name ?? '').' '.($item->vehicle->driver->user?->last_name ?? '')),
                    ] : null,
                ],
            ])->values(),
            'summary' => [
                ...VehicleMaintenanceItem::query()
                    ->current()
                    ->whereHas('vehicle', $vehicleFilter)
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->all(),
                'blocked_vehicles' => Vehicle::whereNotNull('blocked_at')->when($request->boolean('fleet_only'), fn ($v) => $v->where('is_fleet', true))->count(),
                'pending_records' => VehicleMaintenanceRecord::pending()->count(),
            ],
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $items = $this->maintenance->refresh($vehicle);
        $vehicle->refresh()->load(['driver.user', 'vehicleClass']);
        $items->each->setRelation('vehicle', $vehicle);

        return response()->json([
            'vehicle' => new VehicleResource($vehicle),
            'items' => VehicleMaintenanceItemResource::collection($items),
            'records' => VehicleMaintenanceRecordResource::collection(
                VehicleMaintenanceRecord::where('vehicle_id', $vehicle->id)->with('submitter')->orderByDesc('performed_at')->limit(50)->get(),
            ),
            'documents' => DriverDocumentResource::collection(
                DriverDocument::where('vehicle_id', $vehicle->id)->latest()->get(),
            ),
        ]);
    }

    public function storeRecord(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'item_type' => ['required', Rule::in(array_map(fn (MaintenanceItemType $type) => $type->value, MaintenanceItemType::recordable()))],
            'performed_at' => ['required', 'date', 'before_or_equal:today'],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'workshop' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'driver_repair_id' => ['nullable', 'uuid', Rule::exists('driver_repairs', 'id')],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $record = $this->maintenance->submitRecord($vehicle, $request->user(), $validated, $request->file('proof'), byAdmin: true);

        return response()->json([
            'message' => 'Maintenance recorded.',
            'record' => new VehicleMaintenanceRecordResource($record->load(['vehicle', 'submitter'])),
            'items' => VehicleMaintenanceItemResource::collection($this->maintenance->refresh($vehicle->fresh())),
        ], 201);
    }

    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(MaintenanceRecordStatus::class)],
            'vehicle_id' => ['sometimes', 'uuid'],
        ]);

        $records = VehicleMaintenanceRecord::query()
            ->with(['vehicle', 'submitter'])
            ->where('status', $validated['status'] ?? MaintenanceRecordStatus::Pending->value)
            ->when($validated['vehicle_id'] ?? null, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->oldest()
            ->paginate(30);

        return response()->json([
            'records' => VehicleMaintenanceRecordResource::collection($records),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function reviewRecord(Request $request, VehicleMaintenanceRecord $record): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:500'],
        ]);

        $approve = $validated['action'] === 'approve';
        $record = $this->maintenance->reviewRecord($record, $request->user(), $approve, $validated['rejection_reason'] ?? null);

        return response()->json([
            'message' => $approve ? 'Record approved.' : 'Record rejected.',
            'record' => new VehicleMaintenanceRecordResource($record->load(['vehicle', 'submitter'])),
        ]);
    }

    /**
     * Operations upload a paper for a car (typically a fleet car); it is
     * approved at once and replaces the car's earlier paper of that type.
     */
    public function storeDocument(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(DocumentType::vehicleDocumentValues())],
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'expires_at' => [Rule::requiredIf(fn () => in_array($request->input('type'), ['insurance_certificate', 'roadworthiness_certificate', 'vehicle_licence'], true)), 'nullable', 'date'],
        ]);

        $file = $request->file('document');
        $path = $file->store("vehicle-documents/{$vehicle->id}", config('filesystems.uploads'));

        if (! $path) {
            return response()->json(['message' => 'File upload failed. Please try again.'], 503);
        }

        $admin = $request->user();

        try {
            [$document, $replacedPaths] = DB::transaction(function () use ($vehicle, $validated, $file, $path, $admin) {
                $replaced = DriverDocument::where('vehicle_id', $vehicle->id)
                    ->where('type', $validated['type'])
                    ->whereIn('status', [DocumentStatus::Pending, DocumentStatus::Approved])
                    ->get();

                $replaced->each->delete();

                $document = DriverDocument::create([
                    'driver_id' => $vehicle->driver_id,
                    'vehicle_id' => $vehicle->id,
                    'uploaded_by' => $admin->id,
                    'type' => $validated['type'],
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'expires_at' => $validated['expires_at'] ?? null,
                    'status' => DocumentStatus::Approved,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);

                AuditLog::record($document, 'vehicle_document_uploaded_by_admin', $admin, null, ['vehicle_id' => $vehicle->id]);

                return [$document, $replaced->pluck('file_path')->all()];
            });
        } catch (\Throwable $e) {
            Storage::disk(config('filesystems.uploads'))->delete($path);

            throw $e;
        }

        Storage::disk(config('filesystems.uploads'))->delete($replacedPaths);

        return response()->json([
            'message' => 'Document uploaded and approved.',
            'document' => new DriverDocumentResource($document),
            'items' => VehicleMaintenanceItemResource::collection($this->maintenance->refresh($vehicle->fresh())),
        ], 201);
    }

    /**
     * Vehicle papers the migration could not match to one car (the driver
     * had none, or more than one).
     */
    public function unlinkedDocuments(): JsonResponse
    {
        $documents = DriverDocument::query()
            ->whereIn('type', DocumentType::vehicleDocumentValues())
            ->whereNull('vehicle_id')
            ->whereIn('status', [DocumentStatus::Pending, DocumentStatus::Approved])
            ->with('driver.user')
            ->oldest()
            ->paginate(30);

        return response()->json([
            'documents' => $documents->getCollection()->map(fn (DriverDocument $document) => [
                ...(new DriverDocumentResource($document))->resolve(),
                'driver' => $document->driver ? [
                    'id' => $document->driver->id,
                    'name' => trim(($document->driver->user?->first_name ?? '').' '.($document->driver->user?->last_name ?? '')),
                ] : null,
            ])->values(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function linkDocument(Request $request, DriverDocument $document): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid', Rule::exists('vehicles', 'id')],
        ]);

        if (! $document->type->isVehicleDocument()) {
            return response()->json(['message' => 'Only vehicle papers can be linked to a vehicle.'], 422);
        }

        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);
        $old = $document->vehicle_id;
        $document->update(['vehicle_id' => $vehicle->id]);

        AuditLog::record($document, 'vehicle_document_linked', $request->user(), ['vehicle_id' => $old], ['vehicle_id' => $vehicle->id]);

        $this->maintenance->refresh($vehicle);

        if ($old !== null && $old !== $vehicle->id && ($previous = Vehicle::find($old)) !== null) {
            $this->maintenance->refresh($previous);
        }

        return response()->json([
            'message' => 'Document linked to the vehicle.',
            'document' => new DriverDocumentResource($document->fresh()),
        ]);
    }
}
