<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Wallet\RefundToWalletRequest;
use App\Models\AuditLog;
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
