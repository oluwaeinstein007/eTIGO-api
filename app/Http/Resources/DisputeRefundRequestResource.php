<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisputeRefundRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dispute_id' => $this->dispute_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'clawback_driver_earnings' => $this->clawback_driver_earnings,
            'resolution_notes' => $this->resolution_notes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->adminSummary($this->requestedBy)),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->adminSummary($this->reviewedBy)),
            'reviewed_at' => $this->reviewed_at,
            'review_notes' => $this->review_notes,
            'dispute' => $this->whenLoaded('dispute', fn () => [
                'id' => $this->dispute->id,
                'reference_number' => $this->dispute->reference_number,
                'category' => $this->dispute->category->value,
                'category_label' => $this->dispute->category->label(),
                'queue' => $this->dispute->queue->value,
                'escalation_tier' => $this->dispute->escalation_tier,
                'tier_label' => $this->dispute->tierLabel(),
                'status' => $this->dispute->status->value,
                'refund_amount' => $this->dispute->refund_amount,
                'ride_id' => $this->dispute->ride_id,
            ]),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array{id: string, first_name: ?string, last_name: ?string, admin_role: ?string}|null
     */
    private function adminSummary(?User $admin): ?array
    {
        return $admin ? [
            'id' => $admin->id,
            'first_name' => $admin->first_name,
            'last_name' => $admin->last_name,
            'admin_role' => $admin->admin_role?->value,
        ] : null;
    }
}
