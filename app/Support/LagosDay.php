<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Dates picked on the dashboard are Lagos calendar days. Timestamps are stored
 * in UTC, so a day runs from 23:00 UTC the evening before to 23:00 UTC.
 */
class LagosDay
{
    public const TIMEZONE = 'Africa/Lagos';

    /** Midnight in Lagos at the start of the day, in UTC. */
    public static function start(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay()->utc();
    }

    /** Midnight in Lagos at the end of the day (the next day's start), in UTC. */
    public static function end(string $date): CarbonImmutable
    {
        return self::start($date)->addDay();
    }

    /**
     * Keep rows whose column falls on or after the first day and before the
     * end of the last; either day may be left out.
     */
    public static function filter(Builder $query, string $column, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where($column, '>=', self::start($from));
        }

        if ($to) {
            $query->where($column, '<', self::end($to));
        }

        return $query;
    }
}
