<?php

namespace App\Enums;

enum FleetAgreementStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Financially Complete',
            self::Terminated => 'Terminated',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Terminated]);
    }
}
