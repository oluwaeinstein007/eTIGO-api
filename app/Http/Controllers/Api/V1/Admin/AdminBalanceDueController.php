<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BalanceDueReason;
use App\Enums\BalanceDueStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BalanceDueResource;
use App\Models\PassengerBalanceDue;
use App\Services\PassengerBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Finance view of passenger balances due, with the option to waive one.
 */
class AdminBalanceDueController extends Controller
{
    public function __construct(
        private PassengerBalanceService $balanceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(BalanceDueStatus::class)],
            'reason' => ['sometimes', Rule::enum(BalanceDueReason::class)],
            'passenger_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer'],
        ]);

        $query = PassengerBalanceDue::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('reason'), fn ($q, $v) => $q->where('reason', $v))
            ->when($request->input('passenger_id'), fn ($q, $v) => $q->where('passenger_id', $v));

        $items = (clone $query)
            ->with(['ride', 'passenger'])
            ->latest()
            ->paginate($this->perPage($request, 25, 100));

        return response()->json([
            'balances' => BalanceDueResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'summary' => [
                'outstanding_count' => PassengerBalanceDue::outstanding()->count(),
                'outstanding_total' => round((float) PassengerBalanceDue::outstanding()->sum('amount'), 2),
            ],
        ]);
    }

    public function waive(Request $request, PassengerBalanceDue $balanceDue): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $item = $this->balanceService->waive($balanceDue, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['balance_due' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Balance waived.',
            'balance' => new BalanceDueResource($item->load(['ride', 'passenger'])),
        ]);
    }
}
