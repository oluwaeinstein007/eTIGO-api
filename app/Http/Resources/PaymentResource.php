<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'amount' => $this->amount,
            'amount_collected' => $this->when($this->amount_collected !== null, $this->amount_collected),
            'cash_change_amount' => $this->when($this->cash_change_amount !== null, $this->cash_change_amount),
            'currency' => $this->currency,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'tip_amount' => $this->tip_amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'gateway_transaction_id' => $this->when(
                $request->user()?->isAdmin(),
                $this->gateway_transaction_id,
            ),
            'failure_reason' => $this->when(
                $this->failure_reason !== null,
                $this->failure_reason,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
