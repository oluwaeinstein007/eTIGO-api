<?php

namespace App\Http\Resources;

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Account */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Lists add held_total with withSum, so they don't query holds per row.
        $held = array_key_exists('held_total', $this->getAttributes())
            ? (int) $this->held_total
            : $this->activeHoldsTotal();

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'balance' => $this->balance,
            'available_balance' => $this->balance - $held,
            'held' => $held,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner instanceof User ? [
                'id' => $this->owner->id,
                'reference_number' => $this->owner->reference_number,
                'name' => trim($this->owner->first_name.' '.$this->owner->last_name),
                'first_name' => $this->owner->first_name,
                'last_name' => $this->owner->last_name,
                'phone' => $this->owner->phone,
                'email' => $this->owner->email,
            ] : null),
            // When money last moved in or out of the wallet; null if it never has.
            'last_activity_at' => $this->when(
                array_key_exists('last_activity_at', $this->getAttributes()),
                fn () => $this->last_activity_at ? Carbon::parse($this->last_activity_at, 'UTC')->toIso8601String() : null,
            ),
            'frozen' => $this->when($this->isFrozen(), fn () => [
                'frozen_at' => $this->frozen_at?->toIso8601String(),
                'reason' => $this->frozen_reason,
                'frozen_by' => $this->relationLoaded('frozenBy') && $this->frozenBy ? [
                    'id' => $this->frozenBy->id,
                    'name' => trim($this->frozenBy->first_name.' '.$this->frozenBy->last_name),
                    'role' => $this->frozenBy->admin_role?->value,
                ] : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
