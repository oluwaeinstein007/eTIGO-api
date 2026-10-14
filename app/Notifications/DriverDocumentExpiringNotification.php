<?php

namespace App\Notifications;

use App\Models\Driver;
use App\Models\DriverDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverDocumentExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        $daysRemaining = (int) now()->diffInDays($this->document->expires_at, false);
        $driverName = trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? ''));
        $driverId = $this->driver->reference_number ?? 'DRV-'.now()->format('ymd').'-'.substr($this->driver->id, 0, 4);

        return (new MailMessage)
            ->subject("Your {$documentType} expires on {$this->document->expires_at->format('j F')}")
            ->view('emails.driver-document-expiring', [
                'preheader' => "Your {$documentType} expires on {$expiryDate}. {$daysRemaining} days left. Upload your renewed document.",
                'preheaderLabel' => 'Driver document expiring',
                'preheaderTitle' => "Your {$documentType} expires on {$this->document->expires_at->format('j F')}",
                'preheaderSub' => "{$daysRemaining} days left. Upload your renewed licence to keep your documents current.",
                'subject' => "Your {$documentType} expires on {$this->document->expires_at->format('j F')}",
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'documentType' => $documentType,
                'expiryDate' => $expiryDate,
                'daysRemaining' => $daysRemaining,
                'documentEnding' => $this->document->original_filename
                    ? substr($this->document->original_filename, -4)
                    : null,
                'driverName' => $driverName,
                'driverId' => $driverId,
                'updateDocumentUrl' => $updateDocumentUrl,
            ]);
    }
}
