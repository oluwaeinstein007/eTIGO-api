<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\LostItemCategory;
use App\Enums\LostItemClosureReason;
use App\Enums\LostItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LostItemMessageResource;
use App\Http\Resources\LostItemResource;
use App\Models\LostItem;
use App\Services\LostItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Lost & Found queue for support and operations (Phase 2, BE2-LF-07
 * and 08): reports the driver has not answered, overdue returns and
 * passenger requests for help come first under `needs_attention`.
 */
class AdminLostItemController extends Controller
{
    private const RELATIONS = ['ride.city', 'passenger', 'driver.driver.vehicle', 'resolvedBy'];

    public function __construct(
        private readonly LostItemService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string'],
            'category' => ['sometimes', 'string', Rule::enum(LostItemCategory::class)],
            'needs_attention' => ['sometimes', 'boolean'],
            'open' => ['sometimes', 'boolean'],
            'city_id' => ['sometimes', 'uuid'],
            'ride_id' => ['sometimes', 'uuid'],
            'passenger_id' => ['sometimes', 'uuid'],
            'driver_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LostItem::with(self::RELATIONS);

        if ($request->filled('status')) {
            // One status or several, comma-separated.
            $statuses = array_filter(array_map(
                fn (string $status) => LostItemStatus::tryFrom(trim($status)),
                explode(',', (string) $request->input('status')),
            ));
            $query->whereIn('status', $statuses ?: ['__none__']);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->boolean('needs_attention')) {
            $query->needsAttention();
        }

        if ($request->has('open')) {
            $request->boolean('open')
                ? $query->open()
                : $query->whereIn('status', LostItemStatus::terminal());
        }

        foreach (['ride_id', 'passenger_id', 'driver_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->input($column));
            }
        }

        if ($request->filled('city_id')) {
            $query->whereHas('ride', fn ($q) => $q->where('city_id', $request->input('city_id')));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhereHas('ride', fn ($q) => $q->where('reference_number', 'ilike', "%{$search}%"));
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $items = $query->queueOrder()->paginate($request->integer('per_page', 15));

        return response()->json([
            'lost_items' => LostItemResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'summary' => [
                'open' => LostItem::open()->count(),
                'needs_attention' => LostItem::needsAttention()->count(),
                'by_status' => LostItem::query()
                    ->selectRaw('status, COUNT(*) AS total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->map(fn ($total) => (int) $total),
            ],
        ]);
    }

    public function show(LostItem $lostItem): JsonResponse
    {
        return response()->json([
            'lost_item' => new LostItemResource($lostItem->load([...self::RELATIONS, 'messages.sender'])),
        ]);
    }

    /**
     * POST /admin/lost-items/{lostItem}/intervene. One endpoint, several
     * actions:
     * - note: an internal note (`body`), seen only by support;
     * - message: a message to passenger and driver (`body`);
     * - mark_found: the driver or office has the item;
     * - resolve: the item is back with the passenger (`notes`, `waive_return_fee`);
     * - close: closed without the item being returned (`closure_reason`, `notes`).
     */
    public function intervene(Request $request, LostItem $lostItem): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['note', 'message', 'mark_found', 'resolve', 'close'])],
            'body' => ['required_if:action,note,message', 'nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'closure_reason' => ['required_if:action,close', 'nullable', 'string', Rule::enum(LostItemClosureReason::class)],
            'waive_return_fee' => ['sometimes', 'boolean'],
        ]);

        $admin = $request->user();
        $action = $validated['action'];

        try {
            if (in_array($action, ['note', 'message'], true)) {
                $message = $this->service->postMessage($lostItem, $admin, trim($validated['body']), internal: $action === 'note');

                return response()->json([
                    'message' => $action === 'note' ? 'Note added.' : 'Message sent to the passenger and driver.',
                    'lost_item_message' => new LostItemMessageResource($message->load('sender')),
                    'lost_item' => new LostItemResource($lostItem->fresh()->load([...self::RELATIONS, 'messages.sender'])),
                ], 201);
            }

            $item = match ($action) {
                'mark_found' => $this->service->adminMarkFound($lostItem, $admin),
                'resolve' => $this->service->adminResolve($lostItem, $admin, $validated['notes'] ?? null, $request->boolean('waive_return_fee')),
                'close' => $this->service->adminClose($lostItem, $admin, LostItemClosureReason::from($validated['closure_reason']), $validated['notes'] ?? null),
            };
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($action === 'mark_found' && filled($validated['notes'] ?? null)) {
            $this->service->postMessage($item, $admin, trim($validated['notes']), internal: true);
        }

        return response()->json([
            'message' => match ($action) {
                'mark_found' => 'Marked as found. The passenger has been told.',
                'resolve' => 'Report resolved.',
                'close' => 'Report closed.',
            },
            'lost_item' => new LostItemResource($item->load([...self::RELATIONS, 'messages.sender'])),
        ]);
    }
}
