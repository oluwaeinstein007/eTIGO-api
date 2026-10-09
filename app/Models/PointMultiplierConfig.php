<?php

namespace App\Models;

use App\Enums\MultiplierConditionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointMultiplierConfig extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'condition_type',
        'multiplier_value',
        'is_stackable',
    ];

    protected function casts(): array
    {
        return [
            'condition_type' => MultiplierConditionType::class,
            'multiplier_value' => 'decimal:2',
            'is_stackable' => 'boolean',
        ];
    }

    public static function forCondition(MultiplierConditionType $type): ?self
    {
        return static::where('condition_type', $type->value)->first();
    }
}
