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
        $dashboardUrl = config('app.frontend_url', config('app.url')).'/admin/dashboard';
        $role = $notifiable->admin_role
            ? ucwords(str_replace('_', ' ', $notifiable->admin_role->value))
            : 'Administrator';
        $workspaceName = config('app.name', 'Etigo');

        return (new MailMessage)
            ->subject("Welcome to your Etigo workspace, {$notifiable->first_name}")
            ->view('emails.admin-welcome', [
                'preheader' => "Welcome to your Etigo workspace, {$notifiable->first_name}. Your admin account is ready.",
                'preheaderLabel' => 'Admin welcome',
                'preheaderTitle' => "Welcome to your Etigo workspace, {$notifiable->first_name}",
                'preheaderSub' => 'Your admin account is ready. Take a look around your dashboard.',
                'subject' => "Welcome to your Etigo workspace, {$notifiable->first_name}",
                'userName' => $notifiable->first_name,
                'workspaceName' => $workspaceName,
                'roleName' => $role,
                'dashboardUrl' => $dashboardUrl,
                'recipientEmail' => $notifiable->email,
            ]);
    }
}
