<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StoreTipFormRequest;
use App\Http\Resources\PaymentResource;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class RideTipController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
    ) {}

    public function store(StoreTipFormRequest $request, Ride $ride): JsonResponse
    {
        $payment = $this->paymentService->addTip($ride, (float) $request->validated('amount'));

        AuditLog::record($ride, 'tip_added', $request->user(), null, [
            'tip_amount' => $request->validated('amount'),
        ]);

        return response()->json([
            'message' => 'Tip added successfully.',
            'payment' => new PaymentResource($payment),
        ]);
    }
}
