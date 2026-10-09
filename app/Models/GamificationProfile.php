<?php

namespace App\Models;

use App\Enums\TierLevel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GamificationProfile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'total_carbon_score',
        'total_ranking_points',
        'current_tier',
        'tier_upgraded_at',
    ];

    protected function casts(): array
    {
        return [
            'total_carbon_score' => 'decimal:2',
            'total_ranking_points' => 'integer',
            'current_tier' => 'integer',
            'tier_upgraded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function carbonScores(): HasMany
    {
        return $this->hasMany(TripCarbonScore::class, 'user_id', 'user_id');
    }

    public function tierLevel(): TierLevel
    {
        return TierLevel::from($this->current_tier);
    }

    public static function findOrCreateForUser(string $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            [
                'total_carbon_score' => 0,
                'total_ranking_points' => 0,
                'current_tier' => TierLevel::Bronze->value,
            ],
        );
    }
}
