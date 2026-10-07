<?php

namespace App\Models;

use App\Enums\AdminRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminInvitation extends Model
{
    use HasUuids;

    protected $fillable = [
        'email',
        'admin_role',
        'token',
        'invited_by',
        'accepted_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_role' => AdminRole::class,
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }
}
