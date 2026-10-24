<?php

namespace App\Notifications;

use App\Models\AdminInvitation;
use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function __construct(
        public readonly AdminInvitation $invitation,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = config('app.frontend_url', config('app.url')).'/admin/accept-invite?token='.$this->invitation->token;
        $role = ucwords(str_replace('_', ' ', $this->invitation->admin_role->value));
        $inviter = $this->invitation->inviter;
        $inviterName = $inviter ? trim($inviter->first_name.' '.$inviter->last_name) : 'Your administrator';
        $email = $this->invitation->email;
        $workspaceName = config('app.name', 'Etigo');

        return (new MailMessage)
            ->subject("You're invited to the Etigo admin team")
            ->view('emails.admin-invitation', [
                'preheader' => "You're invited to the Etigo admin team. Join {$workspaceName}. Your invitation is valid for 48 hours.",
                'preheaderLabel' => 'Admin invitation',
                'preheaderTitle' => "You're invited to the Etigo admin team",
                'preheaderSub' => "Join {$workspaceName}. Your invitation is valid for 48 hours.",
                'subject' => "You're invited to the Etigo admin team",
                'inviteeFirstName' => $notifiable->first_name ?? null,
                'inviterName' => $inviterName,
                'workspaceName' => $workspaceName,
                'roleName' => $role,
                'acceptUrl' => $acceptUrl,
                'recipientEmail' => $email,
                'expiresAt' => $this->invitation->expires_at->format('j F Y').' at '.
                               $this->invitation->expires_at->format('H:i').' WAT',
            ]);
    }
}
