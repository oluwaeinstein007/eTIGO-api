<?php

namespace App\Enums;

enum TierLevel: int
{
    case Bronze = 1;
    case Silver = 2;
    case Gold = 3;
    case Platinum = 4;
    case Diamond = 5;

    public function label(): string
    {
        return match ($this) {
            self::Bronze => 'Bronze',
            self::Silver => 'Silver',
            self::Gold => 'Gold',
            self::Platinum => 'Platinum',
            self::Diamond => 'Diamond',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Bronze => self::Silver,
            self::Silver => self::Gold,
            self::Gold => self::Platinum,
            self::Platinum => self::Diamond,
            self::Diamond => null,
        };
    }

    public function isMaxTier(): bool
    {
        return $this === self::Diamond;
    }
}
