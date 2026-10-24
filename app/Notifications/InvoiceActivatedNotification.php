<?php

namespace App\Notifications;

use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceActivatedNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function __construct(
        public readonly string $activatedByName,
        public readonly ?string $workspaceName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoicesUrl = config('app.frontend_url', config('app.url')).'/admin/billing/invoices';
        $workspace = $this->workspaceName ?? config('app.name', 'Etigo');

        return (new MailMessage)
            ->subject('Your Etigo invoice access is now active')
            ->view('emails.invoice-activated', [
                'preheader' => "Your Etigo invoice access is now active. View and download invoices for {$workspace}.",
                'preheaderLabel' => 'Invoice activated',
                'preheaderTitle' => 'Your Etigo invoice access is now active',
                'preheaderSub' => "View and download invoices for {$workspace} in your account.",
                'subject' => 'Your Etigo invoice access is now active',
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'workspaceName' => $workspace,
                'activatedBy' => $this->activatedByName,
                'activeFrom' => now()->format('j F Y'),
                'invoicesUrl' => $invoicesUrl,
            ]);
    }
}
