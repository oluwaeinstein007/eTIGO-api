<?php

namespace App\Http\Resources;

use App\Services\PayoutAccountSafeguards;
use Illuminate\Http\Request;

/**
 * A driver's payout bank account as finance sees it in the review queue.
 */
class AdminPayoutAccountResource extends BankAccountResource
{
    public function toArray(Request $request): array
    {
        $user = $this->driver?->user;

        return [
            ...parent::toArray($request),
            'verified_name' => $this->verified_name,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewedBy ? [
                'id' => $this->reviewedBy->id,
                'name' => trim("{$this->reviewedBy->first_name} {$this->reviewedBy->last_name}"),
            ] : null,
            'payout_block' => app(PayoutAccountSafeguards::class)->blockFor($this->resource),
            'driver' => $this->driver ? [
                'id' => $this->driver->id,
                'name' => trim("{$user?->first_name} {$user?->last_name}"),
                'phone' => $user?->phone,
                'kyc_status' => $this->driver->kyc_status?->value,
            ] : null,
        ];
    }
}
