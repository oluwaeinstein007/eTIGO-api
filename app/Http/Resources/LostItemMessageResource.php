<?php

namespace App\Http\Resources;

use App\Models\LostItemMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LostItemMessage
 */
class LostItemMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            // passenger, driver or support
            'sender_role' => $this->sender_role,
            'sender_name' => $this->sender_role === LostItemMessage::ROLE_SUPPORT && ! $viewer?->isAdmin()
                ? 'E-tiGo Support'
                : $this->sender?->first_name,
            'is_mine' => $viewer !== null && $this->sender_user_id === $viewer->id,
            'body' => $this->body,
            'is_internal' => $this->when($viewer?->isAdmin(), $this->is_internal),
            'created_at' => $this->created_at,
        ];
    }
}
