<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LostItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LostItem\StoreLostItemRequest;
use App\Http\Resources\LostItemMessageResource;
use App\Http\Resources\LostItemResource;
use App\Models\LostItem;
use App\Models\Ride;
use App\Services\LostItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Lost & Found for passengers and drivers (Phase 2, BE2-LF-03 to 06).
 * Each user sees the reports on their own trips: as the passenger, or as
 * the driver.
 */
class LostItemController extends Controller
{
    private const RELATIONS = ['ride.city', 'passenger', 'driver.driver.vehicle'];

    public function __construct(
        private readonly LostItemService $service,
    ) {}

    /**
     * POST /rides/{ride}/lost-items: the passenger reports an item left in
     * the car, or the driver reports one they found.
     */
    public function store(StoreLostItemRequest $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        if ($ride->passenger_id !== $user->id && $ride->driver_id !== $user->id) {
            return response()->json(['message' => 'You are not a participant of this ride.'], 403);
        }

        $disk = config('filesystems.uploads');
        $photoPaths = [];

        foreach ($request->file('photos', []) as $photo) {
            $path = $photo->store("lost-items/{$ride->id}", $disk);

            if ($path === false) {
                Storage::disk($disk)->delete($photoPaths);

                return response()->json(['message' => 'Failed to upload the photos. Please try again.'], 500);
            }

            $photoPaths[] = $path;
        }

        try {
            $item = $this->service->report($ride, $user, $request->validated(), $photoPaths);
        } catch (\Throwable $e) {
            if ($photoPaths !== []) {
                Storage::disk($disk)->delete($photoPaths);
            }

            if ($e instanceof \DomainException) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            throw $e;
        }

        $byDriver = $item->reported_by === LostItem::REPORTED_BY_DRIVER;

        return response()->json([
            'message' => match (true) {
                $byDriver => 'Thank you. We have told the passenger you found their item.',
                $item->status === LostItemStatus::DriverNotified => 'We have asked your driver to check the car. We will let you know as soon as they answer.',
                default => 'Your report has been passed to our support team, who will contact you.',
            },
            'lost_item' => new LostItemResource($item->load(self::RELATIONS)),
        ], 201);
    }

    /**
     * GET /lost-items: the reports on the user's trips, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(LostItemStatus::class)],
            'open' => ['sometimes', 'boolean'],
            'ride_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $request->user();
        $column = $user->isDriver() ? 'driver_id' : 'passenger_id';

        $query = LostItem::where($column, $user->id)->with(self::RELATIONS);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('open')) {
            $request->boolean('open')
                ? $query->open()
                : $query->whereIn('status', LostItemStatus::terminal());
        }

        if ($request->filled('ride_id')) {
            $query->where('ride_id', $request->input('ride_id'));
        }

        $items = $query->latest()->paginate($request->integer('per_page', 15));

        return response()->json([
            'lost_items' => LostItemResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * GET /lost-items/{lostItem}: the report with its messages.
     */
    public function show(Request $request, LostItem $lostItem): JsonResponse
    {
        if ($denied = $this->denyUnlessParticipant($request, $lostItem)) {
            return $denied;
        }

        return response()->json([
            'lost_item' => new LostItemResource($lostItem->load([...self::RELATIONS, 'messages.sender'])),
        ]);
    }

    /**
     * POST /lost-items/{lostItem}/respond: the driver says whether they have it.
     */
    public function respond(Request $request, LostItem $lostItem): JsonResponse
    {
        $validated = $request->validate([
            'found' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $lostItem->isDriver($request->user())) {
            return response()->json(['message' => 'Only the driver of this trip can answer this report.'], 403);
        }

        return $this->act(fn () => $this->service->respond($lostItem, $request->user(), (bool) $validated['found'], $validated['note'] ?? null),
            $validated['found'] ? 'Thank you. We have told the passenger you have their item.' : 'Thank you for checking. We have told the passenger.');
    }

    /**
     * POST /lost-items/{lostItem}/returned: the driver has handed it back.
     */
    public function returned(Request $request, LostItem $lostItem): JsonResponse
    {
        if (! $lostItem->isDriver($request->user())) {
            return response()->json(['message' => 'Only the driver of this trip can mark the item returned.'], 403);
        }

        return $this->act(fn () => $this->service->markReturned($lostItem, $request->user()),
            'Marked as returned. We have asked the passenger to confirm.');
    }

    /**
     * POST /lost-items/{lostItem}/received: the passenger has it back.
     */
    public function received(Request $request, LostItem $lostItem): JsonResponse
    {
        if (! $lostItem->isPassenger($request->user())) {
            return response()->json(['message' => 'Only the passenger can confirm the item was received.'], 403);
        }

        return $this->act(fn () => $this->service->confirmReceived($lostItem, $request->user()),
            'Glad you have it back. The report is now resolved.');
    }

    /**
     * POST /lost-items/{lostItem}/cancel: the passenger no longer needs help.
     */
    public function cancel(Request $request, LostItem $lostItem): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $lostItem->isPassenger($request->user())) {
            return response()->json(['message' => 'Only the passenger can cancel this report.'], 403);
        }

        return $this->act(fn () => $this->service->cancel($lostItem, $request->user(), $validated['reason'] ?? null),
            'Your report has been cancelled.');
    }

    /**
     * POST /lost-items/{lostItem}/escalate: the passenger asks support to help.
     */
    public function escalate(Request $request, LostItem $lostItem): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if (! $lostItem->isPassenger($request->user())) {
            return response()->json(['message' => 'Only the passenger can ask support to step in.'], 403);
        }

        return $this->act(fn () => $this->service->escalate($lostItem, $request->user(), $validated['reason']),
            'Our support team will look into this and contact you.');
    }

    /**
     * POST /lost-items/{lostItem}/messages: passenger and driver arrange the return.
     */
    public function storeMessage(Request $request, LostItem $lostItem): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        if ($denied = $this->denyUnlessParticipant($request, $lostItem)) {
            return $denied;
        }

        try {
            $message = $this->service->postMessage($lostItem, $request->user(), trim($validated['body']));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Message sent.',
            'lost_item_message' => new LostItemMessageResource($message->load('sender')),
        ], 201);
    }

    private function denyUnlessParticipant(Request $request, LostItem $lostItem): ?JsonResponse
    {
        $user = $request->user();

        return $lostItem->isPassenger($user) || $lostItem->isDriver($user)
            ? null
            : response()->json(['message' => 'Lost item report not found.'], 404);
    }

    /**
     * @param  callable(): LostItem  $action
     */
    private function act(callable $action, string $message): JsonResponse
    {
        try {
            $item = $action();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $message,
            'lost_item' => new LostItemResource($item->load([...self::RELATIONS, 'messages.sender'])),
        ]);
    }
}
