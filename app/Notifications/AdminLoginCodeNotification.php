<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent straight away (not queued): the admin is waiting at the sign-in
 * screen, and a queue backlog would let the code expire.
 */
class AdminLoginCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int $expiryMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $requestedAt = now();

        return (new MailMessage)
            ->subject("{$this->code} is your Etigo admin sign-in code")
            ->view('emails.admin-login-code', [
                'preheader' => "Your Etigo admin sign-in code is {$this->code}. It expires in {$this->expiryMinutes} minutes.",
                'preheaderLabel' => 'Admin sign-in',
                'preheaderTitle' => 'Your sign-in code',
                'preheaderSub' => "It expires in {$this->expiryMinutes} minutes.",
                'subject' => 'Your Etigo admin sign-in code',
                'userName' => $notifiable->first_name ?? 'there',
                'code' => $this->code,
                'expiryMinutes' => $this->expiryMinutes,
                'recipientEmail' => $notifiable->email,
                'requestedAt' => $requestedAt->copy()->setTimezone('Africa/Lagos')->format('j F Y').' · '.$requestedAt->copy()->setTimezone('Africa/Lagos')->format('H:i').' WAT',
                'ipAddress' => request()->ip(),
            ]);
    }
}
