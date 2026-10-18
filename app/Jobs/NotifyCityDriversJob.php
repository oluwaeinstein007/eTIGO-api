<?php

namespace App\Jobs;

use App\Enums\CityLaunchStatus;
use App\Models\City;
use App\Services\CityLaunchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a city's approved drivers it launched, resumed or paused
 * (CityLaunchService::notifyDrivers). Not retried: nothing records which
 * drivers were told, so a retry would message them twice.
 */
class NotifyCityDriversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $cityId,
        public string $status,
    ) {}

    public function handle(CityLaunchService $service): void
    {
        $city = City::find($this->cityId);

        if ($city !== null) {
            $service->notifyDrivers($city, CityLaunchStatus::from($this->status));
        }
    }
}
