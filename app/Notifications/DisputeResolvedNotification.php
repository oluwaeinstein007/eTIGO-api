<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisputeResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function __construct(
        public readonly Dispute $dispute,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = $this->dispute->status->label();
        $ref = $this->dispute->reference_number;
        $supportUrl = config('app.frontend_url', config('app.url')).'/rides';

        return (new MailMessage)
            ->subject("Report {$ref} — {$status}")
            ->view('emails.dispute-resolved', [
                'preheader' => "Your report {$ref} has been reviewed. Status: {$status}.",
                'preheaderLabel' => 'Dispute update',
                'preheaderTitle' => "Report {$ref} — {$status}",
                'preheaderSub' => 'Our operations team has reached a decision on your report.',
                'subject' => "Report {$ref} — {$status}",
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'referenceNumber' => $ref,
                'status' => $status,
                'resolutionNotes' => $this->dispute->resolution_notes,
                'refundAmount' => $this->dispute->refund_amount,
                'refundMethod' => $this->dispute->refund_method?->value,
                'waivedAmount' => $this->dispute->waived_amount,
                'refundCurrency' => $this->dispute->refund_currency ?? 'NGN',
                'supportUrl' => $supportUrl,
            ]);
    }
}
