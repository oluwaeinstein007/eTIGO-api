<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;

    /**
     * Admin dashboard sessions are short-lived; passenger and driver app
     * sessions last much longer (see config/sanctum.php).
     */
    public function hasOutlivedSession(): bool
    {
        if ($this->expires_at?->isPast()) {
            return true;
        }

        $lifetimeMinutes = $this->tokenable?->isAdmin()
            ? (int) config('sanctum.admin_token_expiration')
            : (int) config('sanctum.app_token_expiration');

        return $lifetimeMinutes > 0 && $this->created_at->lte(now()->subMinutes($lifetimeMinutes));
    }
}
