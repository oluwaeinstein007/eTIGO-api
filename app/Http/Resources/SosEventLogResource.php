<?php

namespace App\Http\Resources;

use App\Models\SosEventLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SosEventLog */
class SosEventLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'description' => $this->description(),
            // "system" for automatic steps (check-in sent, escalation).
            'actor_type' => $this->actorType(),
            'actor_id' => $this->actor_id,
            'actor' => new UserResource($this->whenLoaded('actor')),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function actorType(): string
    {
        if ($this->actor_id === null) {
            return 'system';
        }

        if (! $this->relationLoaded('actor') || $this->actor === null) {
            return 'user';
        }

        return $this->actor->isAdmin() ? 'operator' : $this->actor->type->value;
    }

    private function description(): string
    {
        $metadata = $this->metadata ?? [];

        if ($this->event_type === 'triggered') {
            return 'SOS raised by the '.($metadata['trigger_type'] ?? 'passenger');
        }

        if ($this->event_type === 'operator_reassigned') {
            return 'Alert taken over by another operator';
        }

        if ($this->event_type === 'operator_call') {
            $party = str_replace('_', ' ', $metadata['called_party'] ?? 'passenger');
            $outcome = match ($metadata['outcome'] ?? null) {
                'reached' => 'reached',
                'no_answer' => 'no answer',
                'busy' => 'line busy',
                'unreachable' => 'unreachable',
                default => 'call logged',
            };

            return "Called the {$party}: {$outcome}";
        }

        if (str_starts_with($this->event_type, 'transition:')) {
            $to = explode('->', $this->event_type)[1] ?? '';

            return match ($to) {
                'check_in_sent' => 'Safety check-in sent',
                'acknowledged' => 'Confirmed safe by the person who raised the SOS',
                'escalated' => 'Escalated: no answer to the safety check-in',
                'operator_assigned' => 'Alert taken by an operator',
                'dispatched' => 'Emergency services dispatched ('.($metadata['emergency_service_type'] ?? 'unspecified').')',
                'resolved' => 'Resolved',
                'cancelled' => 'Cancelled',
                default => ucfirst(str_replace('_', ' ', $to)),
            };
        }

        return ucfirst(str_replace('_', ' ', $this->event_type));
    }
}
