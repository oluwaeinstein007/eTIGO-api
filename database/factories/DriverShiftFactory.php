<?php

namespace Database\Factories;

use App\Enums\DriverShiftStatus;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverShift>
 */
class DriverShiftFactory extends Factory
{
    protected $model = DriverShift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addHour()->startOfHour();

        return [
            'driver_id' => Driver::factory()->approved(),
            'vehicle_id' => Vehicle::factory()->fleet()->state(['driver_id' => null, 'shift_mode' => true]),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(8),
            'status' => DriverShiftStatus::Scheduled,
        ];
    }
}
