<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Payment $payment) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('ride.'.$this->payment->ride_id)];
    }

    public function broadcastAs(): string
    {
        return 'payment.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'ride_id' => (string) $this->payment->ride_id,
            'method' => $this->payment->method->value,
            'status' => $this->payment->status->value,
            'amount' => (float) $this->payment->amount,
            'currency' => $this->payment->currency,
            'tip_amount' => (float) $this->payment->tip_amount,
            'updated_at' => $this->payment->updated_at?->toISOString(),
        ];
    }
}
