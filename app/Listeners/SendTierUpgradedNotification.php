<?php

namespace App\Listeners;

use App\Events\TierUpgraded;
use App\Models\User;
use App\Notifications\TierUpgradedNotification;

class SendTierUpgradedNotification
{
    public function handle(TierUpgraded $event): void
    {
        $user = User::find($event->userId);

        $user?->notify(new TierUpgradedNotification(
            tierName: $event->tierName,
            previousTier: $event->previousTier,
            newTier: $event->newTier,
        ));
    }
}
