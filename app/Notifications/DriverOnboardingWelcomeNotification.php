<?php

namespace App\Notifications;

use App\Models\Driver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverOnboardingWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        $applicationUrl = config('app.frontend_url', config('app.url')).'/driver/application';
        $applicationId = $this->driver->reference_number ?? 'DRV-'.now()->format('ymd').'-'.substr($this->driver->id, 0, 4);
        $submittedDate = $this->driver->created_at?->format('j F Y') ?? now()->format('j F Y');

        $documents = ! empty($this->documentTypes)
            ? implode(', ', array_map(fn ($t) => ucwords(str_replace('_', ' ', $t)), $this->documentTypes))
            : 'Identity, driving licence & insurance';

        return (new MailMessage)
            ->subject("We've received your Etigo driver registration")
            ->view('emails.driver-onboarding-welcome', [
                'preheader' => "We've received your Etigo driver registration. Your profile and documents are in review.",
                'preheaderLabel' => 'Driver onboarding welcome',
                'preheaderTitle' => "We've received your Etigo driver registration",
                'preheaderSub' => "Your profile and documents are in review. We'll email you with the outcome.",
                'subject' => "We've received your Etigo driver registration",
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'applicationId' => $applicationId,
                'submittedDate' => $submittedDate,
                'documentsReceived' => $documents,
                'applicationUrl' => $applicationUrl,
            ]);
    }
}
