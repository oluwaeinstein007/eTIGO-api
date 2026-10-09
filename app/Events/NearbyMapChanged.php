<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A privacy-safe invalidation signal; clients refetch their own nearby query. */
class NearbyMapChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $audience) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->audience === 'passengers' ? 'passengers.nearby' : 'drivers.nearby')];
    }

    public function broadcastAs(): string
    {
        return 'nearby.map.changed';
    }

    public function broadcastWith(): array
    {
        return ['changed_at' => now()->timestamp];
    }
}
