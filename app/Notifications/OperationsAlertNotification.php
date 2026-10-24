<?php

namespace App\Notifications;

use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email to operations about something that needs a person: a missed fleet
 * shift, or fleet cars with overdue maintenance or expired papers.
 */
class OperationsAlertNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    /**
     * @param  list<string>  $lines  one entry per car or shift
     */
    public function __construct(
        public readonly string $label,
        public readonly string $headline,
        public readonly string $body,
        public readonly array $lines,
        public readonly string $actionPath,
        public readonly string $actionLabel,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->label}: {$this->headline}")
            ->view('emails.operations-alert', [
                'preheader' => $this->body,
                'subject' => "{$this->label}: {$this->headline}",
                'label' => $this->label,
                'headline' => $this->headline,
                'body' => $this->body,
                'lines' => $this->lines,
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'actionUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').$this->actionPath,
                'actionLabel' => $this->actionLabel,
            ]);
    }
}
