<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverAvailabilityPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voluntary availability planning (Phase 2, OQ-20): drivers say when and
 * where they expect to drive, so operations can spot gaps and target
 * incentives. No penalty for changing or skipping a plan, it never stops a
 * driver going online, and passengers never see it.
 */
class DriverAvailabilityService
{
    public const TIMEZONE = 'Africa/Lagos';

    /**
     * @param  array{starts_at: string, ends_at: string, area_label?: ?string, repeat_weekly?: ?int}  $data
     * @return Collection<int, DriverAvailabilityPlan>
     *
     * @throws ValidationException
     */
    public function create(Driver $driver, array $data): Collection
    {
        $settings = AppSetting::driverScheduling();

        if (! $driver->isApproved() || $driver->city_id === null) {
            throw ValidationException::withMessages(['driver' => ['Only approved drivers with a city can plan their hours.']]);
        }

        $startsAt = Carbon::parse($data['starts_at'])->utc();
        $endsAt = Carbon::parse($data['ends_at'])->utc();
        $repeat = (int) ($data['repeat_weekly'] ?? 0);
        $area = $this->normaliseArea($data['area_label'] ?? null);

        return DB::transaction(function () use ($driver, $startsAt, $endsAt, $repeat, $area, $settings) {
            Driver::lockForUpdate()->find($driver->id);

            $upcoming = DriverAvailabilityPlan::where('driver_id', $driver->id)->upcoming()->count();

            if ($upcoming + $repeat + 1 > $settings['availability_max_upcoming']) {
                throw ValidationException::withMessages(['starts_at' => ["You can have at most {$settings['availability_max_upcoming']} upcoming plans."]]);
            }

            $plans = collect();

            for ($week = 0; $week <= $repeat; $week++) {
                $start = $startsAt->copy()->addWeeks($week);
                $end = $endsAt->copy()->addWeeks($week);
                $this->assertTimes($start, $end, $settings);

                $clash = DriverAvailabilityPlan::where('driver_id', $driver->id)->overlapping($start, $end)->first();

                if ($clash !== null) {
                    throw ValidationException::withMessages(['starts_at' => ["You already plan to drive then ({$this->window($clash->starts_at, $clash->ends_at)})."]]);
                }

                $plans->push(DriverAvailabilityPlan::create([
                    'driver_id' => $driver->id,
                    'city_id' => $driver->city_id,
                    'area_label' => $area,
                    'starts_at' => $start,
                    'ends_at' => $end,
                ]));
            }

            return $plans;
        });
    }

    /**
     * Planned drivers per hour (Lagos time) in a city, overall and per area.
     *
     * @return array<string, mixed>
     */
    public function coverage(City $city, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->setTimezone(self::TIMEZONE)->startOfHour();
        $to = $to->copy()->setTimezone(self::TIMEZONE);

        if ($to->gt($to->copy()->startOfHour())) {
            $to = $to->copy()->startOfHour()->addHour();
        }

        $plans = DriverAvailabilityPlan::where('city_id', $city->id)
            ->overlapping($from->copy()->utc(), $to->copy()->utc())
            ->get(['driver_id', 'area_label', 'starts_at', 'ends_at']);

        $hours = [];
        $peak = null;
        $lowest = null;

        for ($hour = $from->copy(); $hour->lt($to); $hour->addHour()) {
            $end = $hour->copy()->addHour();
            $inHour = $plans->filter(fn (DriverAvailabilityPlan $plan) => $plan->starts_at->lt($end) && $plan->ends_at->gt($hour));

            $areas = $inHour
                ->groupBy(fn (DriverAvailabilityPlan $plan) => mb_strtolower($plan->area_label ?? ''))
                ->map(fn (Collection $group) => [
                    'area_label' => $group->first()->area_label,
                    'planned_drivers' => $group->pluck('driver_id')->unique()->count(),
                ])
                ->sortByDesc('planned_drivers')
                ->values()
                ->all();

            $entry = [
                'starts_at' => $hour->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'planned_drivers' => $inHour->pluck('driver_id')->unique()->count(),
                'areas' => $areas,
            ];

            $hours[] = $entry;
            $peak = $peak === null || $entry['planned_drivers'] > $peak['planned_drivers'] ? $entry : $peak;
            $lowest = $lowest === null || $entry['planned_drivers'] < $lowest['planned_drivers'] ? $entry : $lowest;
        }

        return [
            'city' => ['id' => $city->id, 'name' => $city->name],
            'timezone' => self::TIMEZONE,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'approved_drivers' => Driver::where('city_id', $city->id)->where('status', 'approved')->count(),
            'drivers_with_plans' => $plans->pluck('driver_id')->unique()->count(),
            'areas' => $plans->pluck('area_label')->filter()->unique(fn ($label) => mb_strtolower($label))->sort()->values()->all(),
            'peak_hour' => $peak === null ? null : ['starts_at' => $peak['starts_at'], 'planned_drivers' => $peak['planned_drivers']],
            'lowest_hour' => $lowest === null ? null : ['starts_at' => $lowest['starts_at'], 'planned_drivers' => $lowest['planned_drivers']],
            'hours' => $hours,
        ];
    }

    public function window(Carbon $startsAt, Carbon $endsAt): string
    {
        $start = $startsAt->copy()->setTimezone(self::TIMEZONE);
        $end = $endsAt->copy()->setTimezone(self::TIMEZONE);

        return $start->format('D j M, g:i A').' – '.($start->isSameDay($end) ? $end->format('g:i A') : $end->format('D j M, g:i A'));
    }

    /**
     * Same area typed differently ("lekki ", "Lekki") groups together.
     */
    private function normaliseArea(?string $area): ?string
    {
        $area = trim(preg_replace('/\s+/', ' ', (string) $area));

        return $area === '' ? null : mb_convert_case($area, MB_CASE_TITLE);
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws ValidationException
     */
    private function assertTimes(Carbon $startsAt, Carbon $endsAt, array $settings): void
    {
        if ($startsAt->lt(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['starts_at' => ['Plans must start now or later.']]);
        }

        if ($startsAt->gt(now()->addDays($settings['availability_max_advance_days']))) {
            throw ValidationException::withMessages(['starts_at' => ["You can plan up to {$settings['availability_max_advance_days']} days ahead."]]);
        }

        $minutes = $startsAt->diffInMinutes($endsAt, false);

        if ($minutes < $settings['availability_min_minutes']) {
            throw ValidationException::withMessages(['ends_at' => ["A plan must last at least {$settings['availability_min_minutes']} minutes."]]);
        }

        if ($minutes > $settings['availability_max_hours'] * 60) {
            throw ValidationException::withMessages(['ends_at' => ["A plan can last at most {$settings['availability_max_hours']} hours."]]);
        }
    }
}
