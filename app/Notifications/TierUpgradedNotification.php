<?php

namespace App\Notifications;

use App\Enums\TierLevel;
use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TierUpgradedNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function __construct(
        public readonly string $tierName,
        public readonly int $previousTier,
        public readonly int $newTier,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $previousName = TierLevel::from($this->previousTier)->label();
        $newName = TierLevel::from($this->newTier)->label();
        $benefits = $this->tierBenefits();
        $rewardsUrl = config('app.frontend_url', config('app.url')).'/rewards';

        return (new MailMessage)
            ->subject("You've levelled up to {$this->tierName}!")
            ->view('emails.tier-upgraded', [
                'preheader' => "You've moved from {$previousName} to {$newName}. Check out your new benefits.",
                'preheaderLabel' => 'Rewards',
                'preheaderTitle' => "You've levelled up to {$newName}!",
                'preheaderSub' => "Your eco-friendly rides have earned you {$newName} tier.",
                'subject' => "You've levelled up to {$this->tierName}!",
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'previousTierName' => $previousName,
                'newTierName' => $newName,
                'benefits' => $benefits,
                'rewardsUrl' => $rewardsUrl,
            ]);
    }

    private function tierBenefits(): array
    {
        return match ($this->newTier) {
            2 => ['2% booking fee discount'],
            3 => ['5% booking fee discount', 'Priority surge matching'],
            4 => ['10% booking fee discount', 'Exclusive promotions', 'Discounted booking fees', 'Priority surge matching'],
            5 => ['15% booking fee discount', 'Zero-fee EV reservations', 'All Platinum benefits', 'Priority surge matching'],
            default => ['Welcome to Etigo Rewards!'],
        };
    }
}
