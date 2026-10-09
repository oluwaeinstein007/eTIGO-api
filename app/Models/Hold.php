<?php

namespace App\Models;

use App\Enums\HoldStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hold extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'ride_id',
        'amount',
        'status',
        'expires_at',
        'captured_at',
        'released_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => HoldStatus::class,
            'amount' => 'integer',
            'expires_at' => 'datetime',
            'captured_at' => 'datetime',
            'released_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', HoldStatus::Active);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', HoldStatus::Active)
            ->where('expires_at', '<', now());
    }

    // ── Helpers ──

    public function isActive(): bool
    {
        return $this->status === HoldStatus::Active;
    }
}
