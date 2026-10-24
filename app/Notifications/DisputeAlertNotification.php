<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email to admins about a dispute that needs them: a new safety case for
 * the Safety team, or a missed response target for super admins.
 */
class DisputeAlertNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public const NEW_SAFETY_CASE = 'new_safety_case';

    public const FIRST_RESPONSE_MISSED = 'first_response_missed';

    public const RESOLUTION_MISSED = 'resolution_missed';

    public function __construct(
        public readonly Dispute $dispute,
        public readonly string $reason,
        public readonly string $detail,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref = $this->dispute->reference_number;
        [$label, $headline] = match ($this->reason) {
            self::NEW_SAFETY_CASE => ['New safety case', "Safety case {$ref} needs a first response."],
            self::RESOLUTION_MISSED => ['Resolution target missed', "Dispute {$ref} is overdue for a decision."],
            default => ['Response target missed', "Dispute {$ref} is waiting for a first response."],
        };
        $due = $this->dispute->nextDueAt();

        return (new MailMessage)
            ->subject("{$label}: {$ref}")
            ->view('emails.dispute-alert', [
                'preheader' => $this->detail,
                'subject' => "{$label}: {$ref}",
                'label' => $label,
                'headline' => $headline,
                'body' => $this->detail,
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'referenceNumber' => $ref,
                'category' => $this->dispute->category->label(),
                'tier' => $this->dispute->tierLabel(),
                'dueAt' => $due?->timezone('Africa/Lagos')->format('j M Y, H:i'),
                'caseUrl' => config('app.frontend_url', config('app.url')).'/admin/disputes/'.$this->dispute->id,
            ]);
    }
}
