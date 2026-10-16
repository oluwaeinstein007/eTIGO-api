<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\BankAccount;
use App\Notifications\Channels\AppNotificationChannel;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells the driver their payout account was added or replaced, so a change they
 * did not make is noticed. The SMS goes out whatever their preferences are.
 */
class PayoutAccountChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly BankAccount $bankAccount,
        public readonly bool $replacedAnother,
    ) {}

    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class, SmsChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::PayoutAccountChanged->value,
            'title' => NotificationType::PayoutAccountChanged->label(),
            'body' => $this->message(),
            'bank_account_id' => $this->bankAccount->id,
            'replaced_another' => $this->replacedAnother,
        ];
    }

    public function toSms(object $notifiable): string
    {
        return 'E-tiGo: '.$this->message();
    }

    private function message(): string
    {
        $action = $this->replacedAnother ? 'changed to' : 'set to';
        $lastFour = substr($this->bankAccount->account_number, -4);

        return "Your payout bank account was {$action} {$this->bankAccount->account_name}, account ending {$lastFour}. "
            .'If you did not do this, contact E-tiGo support immediately.';
    }
}
