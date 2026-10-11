<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ConfirmCashFormRequest;
use App\Http\Resources\PaymentResource;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class RidePaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
    ) {}

    public function confirmCash(ConfirmCashFormRequest $request, Ride $ride): JsonResponse
    {
        $payment = $ride->payment;

        if (! $payment) {
            abort(404, 'No payment record found for this ride.');
        }

        $amountCollected = $request->validated('amount_collected');

        $payment = $this->paymentService->confirmCashCollection(
            $payment,
            $amountCollected !== null ? (float) $amountCollected : null,
        );

        AuditLog::record($ride, 'cash_collected', $request->user());

        $message = 'Cash collection confirmed.';
        if ($payment->cash_change_amount && $payment->cash_change_amount > 0) {
            $changeFormatted = number_format((float) $payment->cash_change_amount, 2);
            $message = "Cash collection confirmed. ₦{$changeFormatted} change credited to rider's wallet.";
        }

        return response()->json([
            'message' => $message,
            'payment' => new PaymentResource($payment),
        ]);
    }
}
