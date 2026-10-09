<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\AccountType;
use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StorePayoutRequest;
use App\Http\Resources\PayoutResource;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Payout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayoutController extends Controller
{
    public function store(StorePayoutRequest $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $bankAccount = $driver->primaryBankAccount;
        if (! $bankAccount || ! $bankAccount->is_verified) {
            return response()->json(['message' => 'No verified bank account on file. Please add a bank account first.'], 422);
        }

        $existingPending = Payout::where('driver_id', $driver->id)
            ->whereIn('status', [PayoutStatus::Requested, PayoutStatus::Approved, PayoutStatus::Processing])
            ->exists();

        if ($existingPending) {
            return response()->json(['message' => 'You already have a pending payout request.'], 422);
        }

        $amountKobo = $request->validated('amount');

        $account = Account::where('owner_type', 'App\\Models\\Driver')
            ->where('owner_id', $driver->id)
            ->where('type', AccountType::DriverEarningsAvailable)
            ->first();

        if (! $account || $account->availableBalance() < $amountKobo) {
            return response()->json(['message' => 'Insufficient available balance.'], 422);
        }

        $payout = DB::transaction(function () use ($driver, $bankAccount, $amountKobo, $request) {
            $payout = Payout::create([
                'driver_id' => $driver->id,
                'bank_account_id' => $bankAccount->id,
                'amount' => $amountKobo,
                'status' => PayoutStatus::Requested,
                'requested_at' => now(),
            ]);

            AuditLog::record($payout, 'payout.requested', $request->user());

            return $payout;
        });

        return response()->json([
            'message' => 'Payout request submitted for approval.',
            'payout' => new PayoutResource($payout->load('bankAccount')),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'in:requested,approved,processing,paid,failed,reversed'],
        ]);

        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $query = Payout::where('driver_id', $driver->id)
            ->with('bankAccount')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $payouts = $query->paginate(20);

        return response()->json([
            'payouts' => PayoutResource::collection($payouts),
            'meta' => [
                'current_page' => $payouts->currentPage(),
                'last_page' => $payouts->lastPage(),
                'per_page' => $payouts->perPage(),
                'total' => $payouts->total(),
            ],
        ]);
    }
}
