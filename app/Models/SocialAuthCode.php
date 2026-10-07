<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SocialAuthCode extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'provider',
        'provider_id',
        'email',
        'first_name',
        'last_name',
        'user_type',
        'state',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
