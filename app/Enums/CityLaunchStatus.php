<?php

namespace App\Enums;

/**
 * Where a city is in its launch (Phase 2 multi-city, OQ-22). A city starts
 * in onboarding: drivers sign up and are approved, passengers can only join
 * the waitlist. It goes live once it passes the readiness checklist. Paused
 * stops new bookings; trips in progress and, by default, bookings already
 * made still go ahead.
 */
enum CityLaunchStatus: string
{
    case Onboarding = 'onboarding';
    case Live = 'live';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::Live => 'Live',
            self::Paused => 'Paused',
        };
    }

    /**
     * Passengers can book (now or in advance) and get estimates.
     */
    public function acceptsBookings(): bool
    {
        return $this === self::Live;
    }

    /**
     * Drivers can choose the city when they sign up.
     */
    public function acceptsDriverSignUps(): bool
    {
        return $this !== self::Paused;
    }

    /**
     * Approved drivers can go online. Paused cities keep drivers online for
     * the trips and bookings that still go ahead.
     */
    public function allowsDriversOnline(): bool
    {
        return $this !== self::Onboarding;
    }

    /**
     * Bookings made while the city was live still dispatch after a pause.
     */
    public function dispatchesScheduledRides(): bool
    {
        return $this !== self::Onboarding;
    }

    /**
     * Passengers can ask to be told when the city opens (again).
     */
    public function acceptsWaitlist(): bool
    {
        return $this !== self::Live;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Onboarding => [self::Live],
            self::Live => [self::Paused],
            self::Paused => [self::Live, self::Onboarding],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * What a passenger sees when they try to book here.
     */
    public function unavailableMessage(string $cityName): string
    {
        return match ($this) {
            self::Onboarding => "E-tiGo is not available in {$cityName} yet. Join the waitlist and we will tell you when we launch.",
            self::Paused => "Rides in {$cityName} are paused for now. Please try again later.",
            self::Live => '',
        };
    }
}
