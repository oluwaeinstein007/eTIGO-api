<?php

namespace App\Enums;

/**
 * Who may use a promo, as the dashboard offers it. Stored as completed-ride
 * counts (min_order_count / max_order_count); "custom" is any other pair of
 * counts set through the API.
 */
enum PromoRiderEligibility: string
{
    case All = 'all';
    case FirstRide = 'first_ride';
    case Returning = 'returning';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All passengers',
            self::FirstRide => 'First-time passengers only',
            self::Returning => 'Returning passengers only',
            self::Custom => 'Custom ride count',
        };
    }

    /**
     * @return array{min_order_count: int|null, max_order_count: int|null}
     */
    public function orderCounts(): array
    {
        return match ($this) {
            self::FirstRide => ['min_order_count' => null, 'max_order_count' => 0],
            self::Returning => ['min_order_count' => 1, 'max_order_count' => null],
            self::All, self::Custom => ['min_order_count' => null, 'max_order_count' => null],
        };
    }

    public static function fromOrderCounts(?int $min, ?int $max): self
    {
        $min = $min === 0 ? null : $min;

        return match (true) {
            $min === null && $max === null => self::All,
            $min === null && $max === 0 => self::FirstRide,
            $min === 1 && $max === null => self::Returning,
            default => self::Custom,
        };
    }
}
