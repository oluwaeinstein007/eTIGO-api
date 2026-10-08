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

        $payment = $this->paymentService->confirmCashCollection($payment);

        AuditLog::record($ride, 'cash_collected', $request->user());

        return response()->json([
            'message' => 'Cash collection confirmed.',
            'payment' => new PaymentResource($payment),
        ]);
    }
}
