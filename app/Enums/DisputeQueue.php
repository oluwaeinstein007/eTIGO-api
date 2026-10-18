<?php

namespace App\Enums;

/**
 * Which team owns a dispute (OQ-21). Support works it through tiers 1 to 3;
 * safety concerns skip tier 1 and go to the Safety team.
 */
enum DisputeQueue: string
{
    case Support = 'support';
    case Safety = 'safety';

    public function label(): string
    {
        return match ($this) {
            self::Support => 'Support',
            self::Safety => 'Safety',
        };
    }
}
