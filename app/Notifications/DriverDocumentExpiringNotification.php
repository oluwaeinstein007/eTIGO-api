<?php

namespace App\Notifications;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class DriverDocumentExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    /**
     * The driver, the expiring paper and, from the reminder job, the reminder in the push's words.
     */
    public function __construct(
        public readonly Driver $driver,
        public readonly DriverDocument $document,
        // The reminder in the same words as the push (DriverDocumentComplianceService).
        public readonly ?string $line = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The reminder email: expiring, due today, or expired.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $updateDocumentUrl = config('app.frontend_url', config('app.url')).'/driver/documents';
        $documentType = ucwords(str_replace('_', ' ', $this->document->type->value));
        $expiryDate = $this->document->expires_at->format('j F Y');
        // Calendar days in Lagos: 0 on the expiry date itself, which is still valid.
        $today = now()->setTimezone('Africa/Lagos')->startOfDay();
        $daysRemaining = (int) $today->diffInDays(Carbon::parse($this->document->expires_at->toDateString(), 'Africa/Lagos'), false);
        $isExpired = $daysRemaining < 0;
        $daysRemaining = max(0, $daysRemaining);
        $number = $this->document->document_number;
        $driverName = trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? ''));
        $driverId = $this->driver->reference_number ?? 'DRV-'.now()->format('ymd').'-'.substr($this->driver->id, 0, 4);

        $subject = match (true) {
            $isExpired => "Your {$documentType} has expired",
            $daysRemaining === 0 => "Your {$documentType} expires today",
            default => "Your {$documentType} expires on {$this->document->expires_at->format('j F')}",
        };
        $preheaderSub = $this->line ?? ($isExpired
            ? 'Upload your renewed document now to keep driving.'
            : "{$daysRemaining} days left. Upload your renewed document to keep your records current.");

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
                'reminderLine' => $this->line,
                // The licence stops the driver, and insurance, roadworthiness and
                // the vehicle licence stop the car; government ID never does.
                'blocksDriving' => $this->document->type->blocksDriverWhenExpired() || $this->document->type->isVehicleLegalPaper(),
                'documentEnding' => $number ? mb_substr($number, -4) : null,
                'driverName' => $driverName,
                'driverId' => $driverId,
                'updateDocumentUrl' => $updateDocumentUrl,
            ]);
    }
}
