<?php

namespace Database\Factories;

use App\Enums\LostItemCategory;
use App\Enums\LostItemStatus;
use App\Models\LostItem;
use App\Models\Ride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LostItem>
 */
class LostItemFactory extends Factory
{
    protected $model = LostItem::class;

    public function definition(): array
    {
        return [
            'ride_id' => Ride::factory()->completed(),
            'passenger_id' => fn (array $attributes) => Ride::find($attributes['ride_id'])->passenger_id,
            'driver_id' => fn (array $attributes) => Ride::find($attributes['ride_id'])->driver_id,
            'reported_by' => LostItem::REPORTED_BY_PASSENGER,
            'reported_by_user_id' => fn (array $attributes) => $attributes['passenger_id'],
            'category' => LostItemCategory::Phone,
            'description' => 'Black phone in a blue case, back seat.',
            'status' => LostItemStatus::DriverNotified,
            'driver_notified_at' => now(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => LostItemStatus::DriverConfirmed,
            'driver_responded_at' => now(),
        ]);
    }
}
