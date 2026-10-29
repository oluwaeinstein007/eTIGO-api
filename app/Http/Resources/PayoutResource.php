<?php

namespace App\Http\Resources;

use App\Services\PayoutAccountSafeguards;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            // Short name for the payout ("PO-000123") for finance and the driver.
            'reference_number' => $this->reference_number,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'failure_reason' => $this->when($this->failure_reason !== null, $this->failure_reason),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_at' => $this->when($this->approved_at !== null, $this->approved_at?->toIso8601String()),
            'paid_at' => $this->when($this->paid_at !== null, $this->paid_at?->toIso8601String()),
            'bank_account' => new BankAccountResource($this->whenLoaded('bankAccount')),
            'driver' => $this->when($isAdmin, fn () => new DriverResource($this->whenLoaded('driver'))),
            'approved_by' => $this->when($isAdmin && $this->approved_by_admin_id, fn () => new UserResource($this->whenLoaded('approvedBy'))),
            'rejected_at' => $this->when($this->rejected_at !== null, fn () => $this->rejected_at->toIso8601String()),
            'rejected_by' => $this->when($isAdmin && $this->rejected_by_admin_id, fn () => new UserResource($this->whenLoaded('rejectedBy'))),
            'gateway_transfer_id' => $this->when($isAdmin, $this->gateway_transfer_id),
            // Transfer outcome not confirmed for longer than usual: show the
            // "check status" action (POST /admin/payouts/{id}/verify).
            'needs_attention' => $this->when($isAdmin, fn () => $this->resource->isAwaitingConfirmation()),
            // awaiting_otp (Paystack wants an OTP), not_sent or unconfirmed.
            'attention_reason' => $this->when($isAdmin, fn () => $this->resource->attentionReason()),
            'provider_status' => $this->when($isAdmin, $this->provider_status),
            // Why approve/retry would be refused (409): the account is on hold, under review or rejected.
            'bank_account_block' => $this->when(
                $isAdmin && $this->relationLoaded('bankAccount') && $this->bankAccount !== null,
                fn () => app(PayoutAccountSafeguards::class)->blockFor($this->bankAccount),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
