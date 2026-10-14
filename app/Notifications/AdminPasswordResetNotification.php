<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resetUrl = config('app.frontend_url', config('app.url')).'/admin/reset-password?token='.$this->token.'&email='.urlencode($notifiable->email);
        $requestedAt = now();
        $expiresAt = $requestedAt->copy()->addMinutes(30);

        return (new MailMessage)
            ->subject('Reset your Etigo admin password')
            ->view('emails.admin-password-reset', [
                'preheader' => 'Reset your Etigo admin password. Your secure reset link expires in 30 minutes.',
                'preheaderLabel' => 'Admin password reset',
                'preheaderTitle' => 'Reset your Etigo admin password',
                'preheaderSub' => 'Your secure reset link expires in 30 minutes.',
                'subject' => 'Reset your Etigo admin password',
                'userName' => $notifiable->first_name ?? 'there',
                'resetUrl' => $resetUrl,
                'recipientEmail' => $notifiable->email,
                'requestedAt' => $requestedAt->format('j F Y').' · '.$requestedAt->format('H:i').' WAT',
                'expiresAt' => $expiresAt->format('H:i').' WAT on '.$expiresAt->format('j F Y'),
            ]);
    }
}
