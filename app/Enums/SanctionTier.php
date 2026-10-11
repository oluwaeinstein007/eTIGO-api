<?php

namespace App\Enums;

enum SanctionTier: int
{
    case Warning = 1;
    case Suspension = 2;
    case Deactivation = 3;

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Warning',
            self::Suspension => '48-Hour Suspension',
            self::Deactivation => 'Permanent Deactivation',
        };
    }

    public function action(): string
    {
        return match ($this) {
            self::Warning => 'warning_issued',
            self::Suspension => 'suspended_48h',
            self::Deactivation => 'pending_admin_review',
        };
    }

    public function requiresAdminReview(): bool
    {
        return $this === self::Deactivation;
    }
}
