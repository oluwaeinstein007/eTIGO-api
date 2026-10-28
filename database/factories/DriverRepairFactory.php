<?php

namespace Database\Factories;

use App\Enums\RepairRequestStatus;
use App\Enums\RepairServiceType;
use App\Models\DriverRepair;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverRepair>
 */
class DriverRepairFactory extends Factory
{
    protected $model = DriverRepair::class;

    public function definition(): array
    {
        return [
            'driver_id' => User::factory()->driver(),
            'reference_number' => 'REP-'.fake()->unique()->numberBetween(90000, 99999),
            'type' => RepairServiceType::Repair,
            'status' => RepairRequestStatus::InProgress,
            'title' => 'Brake noise',
            'details' => 'Squeal when braking at low speed.',
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time' => '10:00 am',
            'progress_steps' => [],
            'attachments' => [],
        ];
    }
}
