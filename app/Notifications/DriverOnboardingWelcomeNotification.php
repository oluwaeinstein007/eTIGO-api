<?php

namespace App\Notifications;

use App\Models\Driver;
use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverOnboardingWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function __construct(
        public readonly Driver $driver,
        public readonly array $documentTypes = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $applicationUrl = config('app.landing_page_url', config('app.url')).'/driver/application';
        $applicationId = $this->driver->reference_number ?? 'DRV-'.now()->format('ymd').'-'.substr($this->driver->id, 0, 4);
        $submittedDate = $this->driver->created_at?->format('j F Y') ?? now()->format('j F Y');

        // Registration creates the driver profile before any document exists
        // to upload, so an empty list means "nothing uploaded yet" rather
        // than "received" — the subject and body below branch on that.
        $hasDocuments = ! empty($this->documentTypes);
        $documents = $hasDocuments
            ? implode(', ', array_map(fn ($t) => ucwords(str_replace('_', ' ', $t)), $this->documentTypes))
            : 'Identity, driving licence & insurance';

        $subject = $hasDocuments
            ? "We've received your Etigo driver registration"
            : "You're almost there — upload your documents to drive with Etigo";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.driver-onboarding-welcome', [
                'preheader' => $hasDocuments
                    ? "We've received your Etigo driver registration. Your profile and documents are in review."
                    : 'Your Etigo driver profile is set up. Upload your documents to start the review.',
                'preheaderLabel' => 'Driver onboarding welcome',
                'preheaderTitle' => $subject,
                'preheaderSub' => $hasDocuments
                    ? "Your profile and documents are in review. We'll email you with the outcome."
                    : "Upload {$documents} to continue your application.",
                'subject' => $subject,
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'applicationId' => $applicationId,
                'submittedDate' => $submittedDate,
                'documentsReceived' => $documents,
                'documentsUploaded' => $hasDocuments,
                'applicationUrl' => $applicationUrl,
            ]);
    }
}
