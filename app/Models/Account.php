<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Account extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'type',
        'currency',
        'status',
        'balance',
        'balance_version',
        'frozen_at',
        'frozen_reason',
        'frozen_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'status' => AccountStatus::class,
            'balance' => 'integer',
            'balance_version' => 'integer',
            'frozen_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function frozenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by_admin_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', AccountStatus::Active);
    }

    public function scopeFrozen($query)
    {
        return $query->where('status', AccountStatus::Frozen);
    }

    public function scopeForOwner($query, Model $owner)
    {
        return $query->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }

    public function scopeOfType($query, AccountType $type)
    {
        return $query->where('type', $type);
    }

    // ── Helpers ──

    public function activeHoldsTotal(): int
    {
        return (int) $this->holds()
            ->where('status', 'active')
            ->sum('amount');
    }

    public function availableBalance(): int
    {
        return $this->balance - $this->activeHoldsTotal();
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    public function isFrozen(): bool
    {
        return $this->status === AccountStatus::Frozen;
    }
}
