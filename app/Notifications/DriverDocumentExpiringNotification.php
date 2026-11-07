<?php

namespace App\Notifications;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverDocumentExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function __construct(
        public readonly Driver $driver,
        public readonly DriverDocument $document,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $updateDocumentUrl = config('app.frontend_url', config('app.url')).'/driver/documents';
        $documentType = ucwords(str_replace('_', ' ', $this->document->type->value));
        $expiryDate = $this->document->expires_at->format('j F Y');
        $isExpired = $this->document->expires_at->isPast();
        $daysRemaining = $isExpired ? 0 : (int) now()->diffInDays($this->document->expires_at, false);
        $driverName = trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? ''));
        $driverId = $this->driver->reference_number ?? 'DRV-'.now()->format('ymd').'-'.substr($this->driver->id, 0, 4);

        $subject = $isExpired
            ? "Your {$documentType} has expired"
            : "Your {$documentType} expires on {$this->document->expires_at->format('j F')}";
        $preheaderSub = $isExpired
            ? 'Upload your renewed document now to keep driving.'
            : "{$daysRemaining} days left. Upload your renewed document to keep your records current.";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.driver-document-expiring', [
                'preheader' => "{$subject}. {$preheaderSub}",
                'preheaderLabel' => 'Driver document expiring',
                'preheaderTitle' => $subject,
                'preheaderSub' => $preheaderSub,
                'subject' => $subject,
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'documentType' => $documentType,
                'expiryDate' => $expiryDate,
                'daysRemaining' => $daysRemaining,
                'isExpired' => $isExpired,
                'documentEnding' => $this->document->document_number ?? null,
                'driverName' => $driverName,
                'driverId' => $driverId,
                'updateDocumentUrl' => $updateDocumentUrl,
            ]);
    }
}
