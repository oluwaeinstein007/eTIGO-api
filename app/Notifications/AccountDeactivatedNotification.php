<?php

namespace App\Notifications;

use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountDeactivatedNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function __construct(
        public readonly ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Etigo account has been deactivated')
            ->view('emails.account-deactivated', [
                'preheader' => 'Your Etigo account has been deactivated by an administrator.',
                'preheaderLabel' => 'Account update',
                'preheaderTitle' => 'Your Etigo account has been deactivated',
                'preheaderSub' => 'You will no longer be able to sign in or use Etigo services.',
                'subject' => 'Your Etigo account has been deactivated',
                'userName' => $notifiable->first_name ?? 'there',
                'recipientEmail' => $notifiable->email,
                'deactivatedAt' => now()->format('j F Y'),
                'reason' => $this->reason,
            ]);
    }
}
