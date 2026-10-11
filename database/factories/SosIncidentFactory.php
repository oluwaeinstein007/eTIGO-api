<?php

namespace Database\Factories;

use App\Enums\SosIncidentStatus;
use App\Enums\SosTriggerType;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SosIncident> */
class SosIncidentFactory extends Factory
{
    protected $model = SosIncident::class;

    public function definition(): array
    {
        return [
            'ride_id' => Ride::factory(),
            'triggered_by_user_id' => User::factory()->passenger(),
            'trigger_type' => SosTriggerType::Passenger,
            'status' => SosIncidentStatus::Triggered,
            'gps_lat' => fake()->latitude(6.0, 10.0),
            'gps_lng' => fake()->longitude(3.0, 8.0),
            'vehicle_details' => [
                'plate_number' => fake()->bothify('??-###-??'),
                'vehicle_class' => 'comfort',
                'color' => fake()->colorName(),
            ],
            'telemetry_data' => [
                'speed_kmh' => fake()->numberBetween(0, 120),
                'heading' => fake()->numberBetween(0, 360),
                'battery_level' => fake()->numberBetween(5, 100),
            ],
        ];
    }

    public function driverTriggered(): static
    {
        return $this->state(fn () => [
            'trigger_type' => SosTriggerType::Driver,
            'triggered_by_user_id' => User::factory()->driver(),
        ]);
    }

    public function checkInSent(): static
    {
        return $this->state(fn () => [
            'status' => SosIncidentStatus::CheckInSent,
            'check_in_sent_at' => now(),
        ]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => [
            'status' => SosIncidentStatus::Acknowledged,
            'check_in_sent_at' => now()->subSeconds(10),
            'check_in_acknowledged_at' => now(),
        ]);
    }

    public function escalated(): static
    {
        return $this->state(fn () => [
            'status' => SosIncidentStatus::Escalated,
            'check_in_sent_at' => now()->subSeconds(35),
            'escalated_at' => now(),
        ]);
    }

    public function operatorAssigned(): static
    {
        return $this->state(fn () => [
            'status' => SosIncidentStatus::OperatorAssigned,
            'check_in_sent_at' => now()->subMinutes(2),
            'escalated_at' => now()->subMinute(),
            'operator_id' => User::factory()->admin(\App\Enums\AdminRole::SafetyOperator),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => SosIncidentStatus::Resolved,
            'check_in_sent_at' => now()->subMinutes(10),
            'escalated_at' => now()->subMinutes(8),
            'operator_id' => User::factory()->admin(\App\Enums\AdminRole::SafetyOperator),
            'operator_notes' => 'Situation resolved. Both parties safe.',
            'resolved_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => SosIncidentStatus::Cancelled,
        ]);
    }
}
