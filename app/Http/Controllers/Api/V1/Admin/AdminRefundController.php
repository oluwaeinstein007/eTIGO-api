<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RideStatus;
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
        $amountKobo = (int) $request->validated('amount');
        $reason = $request->validated('reason');

        if ($ride->status !== RideStatus::Completed) {
            return response()->json(['message' => 'Only completed rides can be refunded.'], 422);
        }

        $ride->loadMissing('payment');

        $maxRefundable = max(
            0,
            $this->refundService->paidAmountKobo($ride) - $this->refundService->refundedAmountKobo($ride),
        );

        if ($amountKobo > $maxRefundable) {
            return response()->json([
                'message' => 'Refund exceeds remaining refundable amount of ₦'.number_format($maxRefundable / 100, 2).'.',
                'max_refundable_kobo' => $maxRefundable,
            ], 422);
        }

        try {
            $result = $this->refundService->refundRide(
                $ride,
                $amountKobo,
                $reason,
                $request->user(),
                $request->boolean('clawback_driver_earnings', true),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::record($ride, 'ride.refunded', $request->user(), null, [
            'amount_kobo' => $amountKobo,
            'clawback_kobo' => $result['clawback_kobo'],
            'reason' => $reason,
        ]);

        return response()->json([
            'message' => 'Refund credited to passenger wallet.',
            'refund_kobo' => $result['refund_kobo'],
            'driver_clawback_kobo' => $result['clawback_kobo'],
            'remaining_refundable_kobo' => $maxRefundable - $amountKobo,
        ]);
    }
}
