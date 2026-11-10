<?php

namespace App\Notifications;

use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security notice after an admin changes their password while signed in, so
 * a change made with a stolen session does not go unnoticed.
 */
class AdminPasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $changedAt = now();

        return (new MailMessage)
            ->subject('Your Etigo admin password was changed')
            ->view('emails.admin-password-changed', [
                'preheader' => 'Your Etigo admin password was changed. If this was not you, reset it now.',
                'preheaderLabel' => 'Security notice',
                'preheaderTitle' => 'Your Etigo admin password was changed',
                'preheaderSub' => 'If this was not you, reset your password now.',
                'subject' => 'Your Etigo admin password was changed',
                'userName' => $notifiable->first_name ?? 'there',
                'resetUrl' => config('app.admin_frontend_url', config('app.url')).'/auth/forgot-password',
                'recipientEmail' => $notifiable->email,
                'changedAt' => $changedAt->format('j F Y').' · '.$changedAt->format('H:i').' WAT',
            ]);
    }
}
