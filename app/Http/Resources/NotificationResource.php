<?php

namespace App\Http\Resources;

use App\Enums\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = NotificationType::tryFrom($this->type);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $type?->label() ?? $this->type,
            'category' => $type?->category()->value,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
