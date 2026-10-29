<?php

namespace App\Http\Resources;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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
            // passenger, driver or system (E-tiGo's own accounts have no owner).
            'owner_type' => self::ownerType($this->resource),
            'owner' => $this->whenLoaded('owner', fn () => self::ownerSummary($this->owner)),
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

    public static function ownerType(Account $account): string
    {
        return match (true) {
            $account->type->isDriverAccount() => 'driver',
            $account->type === AccountType::PassengerWallet => 'passenger',
            default => 'system',
        };
    }

    /**
     * Who the account belongs to. For a driver's account, id is the driver
     * profile id (as in /admin/drivers/{id}) and user_id their user.
     * Load the owner with Driver's user to avoid a query per account.
     *
     * @return array{id: string, user_id: string|null, reference_number: string|null, name: string, first_name: string|null, last_name: string|null, phone: string|null, email: string|null}|null
     */
    public static function ownerSummary(?Model $owner): ?array
    {
        $user = match (true) {
            $owner instanceof User => $owner,
            $owner instanceof Driver => $owner->user,
            default => null,
        };

        if ($user === null) {
            return null;
        }

        return [
            'id' => $owner->getKey(),
            'user_id' => $user->id,
            'reference_number' => $owner->reference_number ?? $user->reference_number,
            'name' => trim($user->first_name.' '.$user->last_name),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'email' => $user->email,
        ];
    }
}
