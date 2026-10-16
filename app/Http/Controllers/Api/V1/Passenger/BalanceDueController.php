<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Resources\BalanceDueResource;
use App\Services\PassengerBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What the passenger owes (late-cancellation fees and unpaid wallet fares)
 * and paying it. New bookings are refused while anything is outstanding.
 */
class BalanceDueController extends Controller
{
    public function __construct(
        private PassengerBalanceService $balanceService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $passenger = $request->user();
        $items = $this->balanceService->outstanding($passenger);

        return response()->json([
            'total' => $this->balanceService->outstandingTotal($passenger),
            'currency' => $items->first()->currency ?? 'NGN',
            'blocks_booking' => $this->balanceService->blockingTotal($passenger) !== null,
            'items' => BalanceDueResource::collection($items),
        ]);
    }

    public function pay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'in:wallet,card'],
        ]);

        try {
            $paid = $this->balanceService->pay($request->user(), $validated['payment_method']);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['payment_method' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Balance paid. You can book again.',
            'paid' => BalanceDueResource::collection($paid->each->load('ride')),
            'total' => $this->balanceService->outstandingTotal($request->user()),
        ]);
    }
}
