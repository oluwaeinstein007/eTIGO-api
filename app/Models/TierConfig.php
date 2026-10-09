<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TierConfig extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tier_level',
        'tier_name',
        'min_points_required',
        'booking_fee_discount_pct',
        'ev_reservation_fee_waived',
        'priority_matching_enabled',
    ];

    protected function casts(): array
    {
        return [
            'tier_level' => 'integer',
            'min_points_required' => 'integer',
            'booking_fee_discount_pct' => 'decimal:2',
            'ev_reservation_fee_waived' => 'boolean',
            'priority_matching_enabled' => 'boolean',
        ];
    }

    public static function forLevel(int $level): ?self
    {
        return static::where('tier_level', $level)->first();
    }

    public static function allOrderedByLevel(): Collection
    {
        return static::orderBy('tier_level')->get();
    }
}
