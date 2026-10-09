<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Wallet\RefundToWalletRequest;
use App\Models\AuditLog;
use App\Models\Journal;
use App\Models\Ride;
use App\Services\WalletRefundService;
use Illuminate\Http\JsonResponse;

class AdminRefundController extends Controller
{
    public function __construct(private WalletRefundService $refundService) {}

    public function refundToWallet(RefundToWalletRequest $request, Ride $ride): JsonResponse
    {
        $amountKobo = $request->validated('amount');
        $reason = $request->validated('reason');

        if (! $ride->isTerminal()) {
            return response()->json(['message' => 'Ride must be completed or cancelled to issue a refund.'], 422);
        }

        $previousRefunds = Journal::where('idempotency_key', 'like', "refund-{$ride->id}-%")
            ->join('ledger_entries', 'journals.id', '=', 'ledger_entries.journal_id')
            ->where('ledger_entries.type', 'credit')
            ->sum('ledger_entries.amount');

        $maxRefundable = ($ride->final_fare ?? $ride->estimated_fare ?? 0) - $previousRefunds;

        if ($amountKobo > $maxRefundable) {
            return response()->json(['message' => "Refund exceeds remaining refundable amount of ₦".number_format($maxRefundable / 100, 2).'.'], 422);
        }

        try {
            $this->refundService->refundToWallet($ride, $amountKobo, $reason);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::record($ride, 'ride.refunded', $request->user(), null, [
            'amount_kobo' => $amountKobo,
            'reason' => $reason,
        ]);

        return response()->json(['message' => 'Refund credited to passenger wallet.']);
    }
}
