<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionConfig extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'rate',
        'driver_id',
        'is_active',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('driver_id');
    }

    public function scopeForDriver($query, string $driverId)
    {
        return $query->where('driver_id', $driverId);
    }

    public function isGlobal(): bool
    {
        return $this->driver_id === null;
    }
}
