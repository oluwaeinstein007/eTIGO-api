<?php

namespace App\Notifications;

use App\Enums\TierLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TierUpgradedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        $benefits = $this->tierBenefits();

        $mail = (new MailMessage)
            ->subject("You've levelled up to {$this->tierName}!")
            ->greeting("Congratulations {$notifiable->first_name}!")
            ->line("Your lower carbon footprint has unlocked **{$this->tierName}** tier (up from {$previousName}).")
            ->line('Your new benefits:');

        foreach ($benefits as $benefit) {
            $mail->line("- {$benefit}");
        }

        return $mail
            ->action('View Rewards', config('app.frontend_url', config('app.url')).'/rewards')
            ->salutation('Keep riding green!');
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
