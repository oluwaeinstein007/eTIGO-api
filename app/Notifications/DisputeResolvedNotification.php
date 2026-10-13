<?php

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisputeResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Dispute $dispute,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = $this->dispute->status->label();
        $ref = $this->dispute->reference_number;

        $mail = (new MailMessage)
            ->subject("Report {$ref} — {$status}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("Your report **{$ref}** has been reviewed by our operations team.")
            ->line("**Status:** {$status}");

        if ($this->dispute->resolution_notes) {
            $mail->line("**Resolution notes:** {$this->dispute->resolution_notes}");
        }

        if ($this->dispute->refund_amount) {
            $currency = $this->dispute->refund_currency ?? 'NGN';
            $mail->line("A refund of **{$currency} ".number_format($this->dispute->refund_amount, 2).'** has been issued to your original payment method.');
        }

        return $mail
            ->line('Thank you for helping us improve your ride experience.')
            ->salutation('— The Etigo Team');
    }
}
