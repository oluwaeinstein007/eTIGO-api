<?php

namespace App\Enums;

/**
 * The city readiness checklist (OQ-22). Computed gates are checked from
 * the data; manual gates are confirmed by an admin and stored on the city.
 * A city can go live for the first time only when every required gate
 * passes.
 */
enum CityReadinessGate: string
{
    case Boundary = 'boundary';
    case Pricing = 'pricing';
    case Drivers = 'drivers';
    case EvCharging = 'ev_charging';
    case SupportCover = 'support_cover';
    case Permits = 'permits';
    case LaunchDate = 'launch_date';
    case LaunchPromos = 'launch_promos';

    public function label(): string
    {
        return match ($this) {
            self::Boundary => 'Map boundary drawn, with no overlap',
            self::Pricing => 'Prices set for every vehicle class offered',
            self::Drivers => 'Enough approved, KYC-verified drivers',
            self::EvCharging => 'Charging stations live for plug-in drivers',
            self::SupportCover => 'Support and Safety cover for the city',
            self::Permits => 'State transport permits or registrations',
            self::LaunchDate => 'Launch date agreed with the client',
            self::LaunchPromos => 'Launch promo codes limited to the city',
        };
    }

    /**
     * Confirmed by an admin rather than computed.
     */
    public function isManual(): bool
    {
        return in_array($this, [self::SupportCover, self::Permits], true);
    }

    /**
     * Blocks the first launch until it passes.
     */
    public function isRequired(): bool
    {
        return $this !== self::LaunchPromos;
    }

    /**
     * @return list<self>
     */
    public static function manual(): array
    {
        return array_values(array_filter(self::cases(), fn (self $gate) => $gate->isManual()));
    }
}
