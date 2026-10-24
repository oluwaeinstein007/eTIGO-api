<?php

namespace App\Notifications;

use App\Models\Driver;
use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function __construct(
        public readonly Driver $driver,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $driverAppUrl = config('app.frontend_url', config('app.url')).'/driver';
        $driverName = trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? ''));
        $driverId = $this->driver->reference_number ?? 'DRV-'.now()->format('ymd').'-'.substr($this->driver->id, 0, 4);
        $approvedDate = $this->driver->approved_at?->format('j F Y') ?? now()->format('j F Y');

        return (new MailMessage)
            ->subject("You're approved to drive with Etigo")
            ->view('emails.driver-approved', [
                'preheader' => "You're approved to drive with Etigo. Your documents are approved. Open Etigo Driver to get started.",
                'preheaderLabel' => 'Driver approved',
                'preheaderTitle' => "You're approved to drive with Etigo",
                'preheaderSub' => 'Your documents are approved. Open Etigo Driver to get started.',
                'subject' => "You're approved to drive with Etigo",
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'driverName' => $driverName,
                'driverId' => $driverId,
                'approvedDate' => $approvedDate,
                'driverAppUrl' => $driverAppUrl,
            ]);
    }
}
