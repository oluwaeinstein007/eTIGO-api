<?php

namespace App\Services;

use App\Enums\CancellationReason;
use App\Enums\CityLaunchStatus;
use App\Enums\DriverStatus;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Exceptions\CityLaunchException;
use App\Jobs\CityLaunchStatusChangedJob;
use App\Jobs\NotifyCityDriversJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\CityWaitlistEntry;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves a city between onboarding, live and paused (Phase 2 multi-city,
 * OQ-22). A first launch needs the readiness checklist; resuming a paused
 * city does not. Pausing never touches trips in progress; upcoming bookings
 * go ahead unless the admin cancels them, and then each passenger is told.
 * Going live tells the waitlist; drivers hear about launches and pauses.
 */
class CityLaunchService
{
    public const CITY_PAUSED_MESSAGE = 'Your scheduled ride was cancelled because E-tiGo has paused rides in %s.';

    public function __construct(
        private CityReadinessService $readiness,
        private AppCacheService $cache,
        private RideService $rideService,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  'honour'|'cancel'|null  $scheduledRides  For a pause; the setting when null
     * @return array{city: City, previous_status: CityLaunchStatus, scheduled_rides: string|null, scheduled_rides_to_cancel: int, active_rides: int}
     *
     * @throws CityLaunchException
     */
    public function changeStatus(City $city, CityLaunchStatus $to, User $admin, ?string $reason = null, ?string $scheduledRides = null): array
    {
        $result = DB::transaction(function () use ($city, $to, $admin, $reason, $scheduledRides) {
            $city = City::lockForUpdate()->findOrFail($city->id);
            $from = $city->launch_status;

            if ($from === $to) {
                throw new CityLaunchException("{$city->name} is already {$to->label()}.");
            }

            if (! $from->canTransitionTo($to)) {
                throw new CityLaunchException("{$city->name} cannot move from {$from->label()} to {$to->label()}.");
            }

            if ($from === CityLaunchStatus::Onboarding && $to === CityLaunchStatus::Live) {
                $report = $this->readiness->report($city);

                if (! $report['ready']) {
                    throw new CityLaunchException(
                        "{$city->name} is not ready to launch. Complete the readiness checklist first.",
                        $report,
                    );
                }
            }

            $scheduledRides = $to === CityLaunchStatus::Paused
                ? ($scheduledRides ?? AppSetting::cities()['pause_scheduled_rides'])
                : null;

            // Back to onboarding closes the city to bookings entirely.
            $cancel = $scheduledRides === 'cancel' || $to === CityLaunchStatus::Onboarding;
            $toCancel = $cancel ? $this->readiness->upcomingScheduledRides($city)->count() : 0;

            $city->update([
                'launch_status' => $to,
                'paused_at' => $to === CityLaunchStatus::Paused ? now() : null,
                'pause_reason' => $to === CityLaunchStatus::Paused ? $reason : null,
                'pause_scheduled_rides' => $scheduledRides,
            ]);

            $event = match (true) {
                $to === CityLaunchStatus::Live && $from === CityLaunchStatus::Onboarding => 'city_launched',
                $to === CityLaunchStatus::Live => 'city_resumed',
                $to === CityLaunchStatus::Paused => 'city_paused',
                default => 'city_returned_to_onboarding',
            };

            AuditLog::record(
                $city,
                $event,
                $admin,
                ['launch_status' => $from->value],
                array_filter([
                    'launch_status' => $to->value,
                    'reason' => $reason,
                    'scheduled_rides' => $scheduledRides,
                    'scheduled_rides_to_cancel' => $toCancel ?: null,
                ], fn ($value) => $value !== null),
            );

            return [
                'city' => $city,
                'previous_status' => $from,
                'scheduled_rides' => $cancel ? 'cancel' : $scheduledRides,
                'scheduled_rides_to_cancel' => $toCancel,
                'active_rides' => Ride::where('city_id', $city->id)
                    ->whereIn('status', RideStatus::activeStatuses())
                    ->count(),
                'cancel' => $cancel,
            ];
        });

        $this->cache->invalidateCities();

        CityLaunchStatusChangedJob::dispatch($result['city']->id, $to->value, $admin->id, $result['cancel']);
        NotifyCityDriversJob::dispatch($result['city']->id, $to->value);

        unset($result['cancel']);

        return $result;
    }

    /**
     * The follow-up work of a status change, run on the queue: cancel
     * upcoming bookings if asked and, on going live, tell the waitlist. Both
     * steps are safe to retry. Skipped when the city has moved on since.
     */
    public function applyStatusChange(City $city, CityLaunchStatus $status, ?User $admin, bool $cancelScheduledRides): void
    {
        if ($city->launch_status !== $status) {
            return;
        }

        if ($cancelScheduledRides && $admin !== null) {
            $this->cancelUpcomingBookings($city, $admin);
        }

        if ($status === CityLaunchStatus::Live) {
            $this->notifyWaitlist($city);
        }
    }

    private function cancelUpcomingBookings(City $city, User $admin): void
    {
        $message = sprintf(self::CITY_PAUSED_MESSAGE, $city->name);

        $this->readiness->upcomingScheduledRides($city)
            ->chunkById(100, function ($rides) use ($admin, $message) {
                foreach ($rides as $ride) {
                    try {
                        $this->rideService->cancelRide($ride, $admin, CancellationReason::CityPaused->value, $message);
                    } catch (\Throwable $e) {
                        // The booking may have just been dispatched; the
                        // dispatcher refuses it anyway while the city is closed.
                        Log::warning('Could not cancel a booking in a paused city', [
                            'ride_id' => $ride->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    /**
     * Tell approved drivers in the city it launched, resumed or paused. Run
     * by its own job, never retried, so a failure elsewhere cannot resend it.
     */
    public function notifyDrivers(City $city, CityLaunchStatus $status): void
    {
        if ($city->launch_status !== $status) {
            return;
        }

        [$title, $body] = match ($status) {
            CityLaunchStatus::Live => [
                "E-tiGo is live in {$city->name}",
                'Passengers can now book. Go online to start getting trips.',
            ],
            CityLaunchStatus::Paused => [
                "Rides paused in {$city->name}",
                $city->pause_scheduled_rides === 'cancel'
                    ? 'Passengers cannot book for now and upcoming bookings were cancelled. Trips in progress finish normally.'
                    : 'Passengers cannot book for now. Trips in progress and rides already booked still go ahead.',
            ],
            CityLaunchStatus::Onboarding => [null, null],
        };

        if ($title === null) {
            return;
        }

        Driver::where('city_id', $city->id)
            ->where('status', DriverStatus::Approved)
            ->select(['id', 'user_id'])
            ->chunkById(200, function ($drivers) use ($city, $status, $title, $body) {
                foreach ($drivers as $driver) {
                    $this->notifications->send(
                        userId: $driver->user_id,
                        type: NotificationType::CityStatusChanged,
                        title: $title,
                        body: $body,
                        data: ['city_id' => (string) $city->id, 'launch_status' => $status->value],
                    );
                }
            });
    }

    private function notifyWaitlist(City $city): void
    {
        CityWaitlistEntry::where('city_id', $city->id)
            ->whereNull('notified_at')
            ->chunkById(200, function ($entries) use ($city) {
                foreach ($entries as $entry) {
                    $this->notifications->send(
                        userId: $entry->user_id,
                        type: NotificationType::CityLaunched,
                        title: "E-tiGo is now in {$city->name}",
                        body: 'You can book rides here now. Open the app to get started.',
                        data: ['city_id' => (string) $city->id],
                    );

                    $entry->update(['notified_at' => now()]);
                }
            });
    }
}
