<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PassengerWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $bookRideUrl = config('app.frontend_url', config('app.url')).'/rides/book';

        return (new MailMessage)
            ->subject("Welcome to Etigo, {$notifiable->first_name}. Let's get moving.")
            ->view('emails.passenger-welcome', [
                'preheader' => "Welcome to Etigo, {$notifiable->first_name}. Your account is ready. Your next journey starts with Etigo.",
                'preheaderLabel' => 'Passenger welcome',
                'preheaderTitle' => "Welcome to Etigo, {$notifiable->first_name}. Let's get moving.",
                'preheaderSub' => 'Your account is ready. Your next journey starts with Etigo.',
                'subject' => "Welcome to Etigo, {$notifiable->first_name}. Let's get moving.",
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'bookRideUrl' => $bookRideUrl,
            ]);
    }
}
