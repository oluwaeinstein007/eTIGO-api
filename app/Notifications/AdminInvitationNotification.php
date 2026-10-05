<?php

namespace App\Notifications;

use App\Models\AdminInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AdminInvitation $invitation,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = config('app.frontend_url').'/admin/accept-invite?token='.$this->invitation->token;
        $role = str_replace('_', ' ', $this->invitation->admin_role->value);

        return (new MailMessage)
            ->subject('You\'ve been invited to join Etigo as an admin')
            ->greeting('Hello!')
            ->line("You've been invited to join Etigo as **{$role}**.")
            ->line('Click the button below to set up your account.')
            ->action('Accept Invitation', $acceptUrl)
            ->line('This invitation expires in 48 hours.')
            ->line('If you did not expect this invitation, no action is needed.');
    }
}
