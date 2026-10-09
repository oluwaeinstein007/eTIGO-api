<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use App\Models\Account;
use App\Services\DriverEarningsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverLedgerController extends Controller
{
    public function __construct(private DriverEarningsService $earningsService) {}

    public function summary(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $summary = $this->earningsService->getSummary($driver->id);

        return response()->json([
            'earnings' => [
                'currency' => 'NGN',
                'available' => $summary['available'],
                'total_paid' => $summary['total_paid'],
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', 'in:debit,credit'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $account = Account::where('owner_type', 'App\\Models\\Driver')
            ->where('owner_id', $driver->id)
            ->where('type', AccountType::DriverEarningsAvailable)
            ->first();

        if (! $account) {
            return response()->json([
                'transactions' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0],
            ]);
        }

        $query = $account->entries()
            ->with('journal')
            ->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to').' 23:59:59');
        }

        $entries = $query->paginate(20);

        return response()->json([
            'transactions' => LedgerEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }
}
