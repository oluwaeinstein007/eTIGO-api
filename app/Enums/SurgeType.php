<?php

namespace App\Enums;

enum SurgeType: string
{
    case TimeBased = 'time_based';
    case DemandBased = 'demand_based';
    case Manual = 'manual';

    /**
     * The conditions keys this type reads; a rule keeps only these.
     *
     * @return list<string>
     */
    public function conditionKeys(): array
    {
        return match ($this) {
            self::TimeBased => ['days_of_week', 'start_time', 'end_time'],
            self::DemandBased => ['min_demand_supply_ratio', 'min_requests'],
            self::Manual => [],
        };
    }

    /**
     * Every conditions key any type reads.
     *
     * @return list<string>
     */
    public static function allConditionKeys(): array
    {
        return array_merge(...array_map(fn (self $type) => $type->conditionKeys(), self::cases()));
    }
}
