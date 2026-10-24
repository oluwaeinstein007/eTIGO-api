<?php

namespace App\Notifications;

use App\Models\Ride;
use App\Notifications\Concerns\ThrottlesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesMail;

    public function __construct(
        public readonly Ride $ride,
        public readonly int $amountKobo,
        public readonly string $paymentMethod,
        public readonly ?string $paymentReference = null,
        public readonly ?string $cardLast4 = null,
        public readonly ?string $cardBrand = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $currency = $this->ride->fare_currency ?? 'NGN';
        $currencySymbol = $this->currencySymbol($currency);
        $totalAmount = number_format($this->amountKobo / 100, 2);
        $paymentDate = now();
        $ref = $this->paymentReference ?? 'PAY-'.now()->format('ymd').'-'.str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $methodDetail = match ($this->paymentMethod) {
            'card' => $this->cardLast4
                ? ($this->cardBrand ? ucfirst($this->cardBrand) : 'Card')." ending in {$this->cardLast4}"
                : 'Card payment',
            'wallet' => 'Etigo Wallet',
            'bank_transfer' => 'Bank Transfer',
            default => ucfirst($this->paymentMethod),
        };

        $rideRef = $this->ride->reference_number ?? 'ETG-'.now()->format('ymd').'-'.substr($this->ride->id ?? '0000', 0, 4);

        return (new MailMessage)
            ->subject("Payment successful · {$currencySymbol}{$totalAmount}")
            ->view('emails.payment-receipt', [
                'preheader' => "Payment successful · {$currencySymbol}{$totalAmount}. Your {$this->paymentMethod} payment was successful.",
                'preheaderLabel' => 'Payment receipt',
                'preheaderTitle' => "Payment successful · {$currencySymbol}{$totalAmount}",
                'preheaderSub' => "Your {$this->paymentMethod} payment was successful. Keep this confirmation for your records.",
                'subject' => "Payment successful · {$currencySymbol}{$totalAmount}",
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'currency' => $currency,
                'currencySymbol' => $currencySymbol,
                'totalAmount' => $totalAmount,
                'paymentMethod' => ucfirst($this->paymentMethod),
                'paymentMethodDetail' => $methodDetail,
                'paymentReference' => $ref,
                'paymentDate' => $paymentDate->format('j F Y').' · '.$paymentDate->format('H:i').' WAT',
                'paidBy' => trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? '')),
                'paymentFor' => "Ride {$rideRef}",
                'receiptUrl' => config('app.frontend_url', config('app.url')).'/payments/'.$ref,
            ]);
    }

    private function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'NGN' => "\u{20A6}",
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            default => $currency.' ',
        };
    }
}
