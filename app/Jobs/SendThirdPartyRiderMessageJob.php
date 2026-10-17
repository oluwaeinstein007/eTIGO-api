<?php

namespace App\Jobs;

use App\Services\ThirdPartyRoutingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one WhatsApp/SMS message to the rider of a third-party booking
 * (ThirdPartyRoutingService). A message neither channel accepts is retried;
 * once the retries run out the driver-details message is marked failed and
 * the booker is shown the PIN.
 */
class SendThirdPartyRiderMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [15, 45];

    public function __construct(
        public readonly string $rideId,
        public readonly string $type,
    ) {}

    public function handle(ThirdPartyRoutingService $routing): void
    {
        $routing->send($this->rideId, $this->type);
    }

    public function failed(?\Throwable $exception): void
    {
        app(ThirdPartyRoutingService::class)->markFailed($this->rideId, $this->type);
    }
}
