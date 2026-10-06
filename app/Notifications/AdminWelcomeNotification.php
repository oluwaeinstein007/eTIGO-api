<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loginUrl = config('app.frontend_url').'/admin/login';
        $role = $notifiable->admin_role
            ? str_replace('_', ' ', $notifiable->admin_role->value)
            : 'admin';

        return (new MailMessage)
            ->subject('Welcome to Etigo!')
            ->greeting("Welcome, {$notifiable->first_name}!")
            ->line("Your admin account has been set up successfully. You've been assigned the **{$role}** role.")
            ->line('You can now log in to the admin dashboard to get started.')
            ->action('Go to Dashboard', $loginUrl)
            ->line('If you have any questions, reach out to your team lead.');
    }
}
