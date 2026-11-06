<?php

namespace App\Events;

use App\Enums\DriverStatus;
use App\Models\Driver;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DriverStatusUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $driverId,
        public string $driverUserId,
        public string $status,
        public ?string $previousStatus = null,
        public ?string $kycStatus = null,
        public ?string $rejectionReason = null,
        public ?string $suspensionReason = null,
        public ?string $occurredAt = null,
    ) {
        $this->occurredAt ??= now()->toISOString();
    }

    public static function fromDriver(Driver $driver, ?DriverStatus $previousStatus = null): self
    {
        $statusValue = $driver->status instanceof DriverStatus
            ? $driver->status->value
            : (string) $driver->status;

        $kycStatusValue = $driver->kyc_status !== null
            ? ($driver->kyc_status instanceof \BackedEnum ? $driver->kyc_status->value : (string) $driver->kyc_status)
            : null;

        return new self(
            driverId: (string) $driver->id,
            driverUserId: (string) $driver->user_id,
            status: $statusValue,
            previousStatus: $previousStatus?->value,
            kycStatus: $kycStatusValue,
            rejectionReason: $driver->rejection_reason,
            suspensionReason: $driver->suspension_reason,
            occurredAt: now()->toISOString(),
        );
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("driver.{$this->driverUserId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'driver.status.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'driver_id' => $this->driverId,
            'driver_user_id' => $this->driverUserId,
            'status' => $this->status,
            'previous_status' => $this->previousStatus,
            'kyc_status' => $this->kycStatus,
            'rejection_reason' => $this->rejectionReason,
            'suspension_reason' => $this->suspensionReason,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
