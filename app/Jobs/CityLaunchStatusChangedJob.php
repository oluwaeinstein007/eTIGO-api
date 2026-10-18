<?php

namespace App\Jobs;

use App\Enums\CityLaunchStatus;
use App\Models\City;
use App\Models\User;
use App\Services\CityLaunchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * After a city changes launch status (CityLaunchService::applyStatusChange):
 * cancels upcoming bookings when the admin chose to, tells the city's
 * drivers, and on going live tells the waitlist.
 */
class CityLaunchStatusChangedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $cityId,
        public string $status,
        public ?string $adminId,
        public bool $cancelScheduledRides,
    ) {}

    public function handle(CityLaunchService $service): void
    {
        $city = City::find($this->cityId);

        if ($city === null) {
            return;
        }

        $service->applyStatusChange(
            $city,
            CityLaunchStatus::from($this->status),
            $this->adminId ? User::find($this->adminId) : null,
            $this->cancelScheduledRides,
        );
    }
}
