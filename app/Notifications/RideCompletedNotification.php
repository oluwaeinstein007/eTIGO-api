<?php

namespace App\Notifications;

use App\Models\Ride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RideCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ride $ride,
        public readonly array $fareDetails,
        public readonly ?string $mapImageUrl = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $breakdown = $this->fareDetails['fare_breakdown'];
        $currency = $this->ride->fare_currency ?? 'NGN';
        $currencySymbol = $this->currencySymbol($currency);
        $finalFare = $this->fareDetails['final_fare'];
        $paymentMethod = $this->ride->payment_method?->label() ?? 'Card';

        $pickupArea = $this->extractArea($this->ride->pickup_address);
        $dropoffArea = $this->extractArea($this->ride->destination_address);

        $fareItems = [];
        if (($breakdown['base_fare'] ?? 0) > 0) {
            $fareItems[] = ['label' => 'Base fare', 'amount' => number_format($breakdown['base_fare'], 2)];
        }
        if (($breakdown['distance_charge'] ?? 0) > 0 || ($breakdown['time_charge'] ?? 0) > 0) {
            $combined = ($breakdown['distance_charge'] ?? 0) + ($breakdown['time_charge'] ?? 0);
            $fareItems[] = ['label' => 'Distance & time', 'amount' => number_format($combined, 2)];
        }
        if (($breakdown['waiting_charge'] ?? 0) > 0) {
            $fareItems[] = ['label' => 'Waiting charge', 'amount' => number_format($breakdown['waiting_charge'], 2)];
        }
        if (($breakdown['promo_discount'] ?? 0) > 0) {
            $fareItems[] = ['label' => 'Promo discount', 'amount' => '-'.number_format($breakdown['promo_discount'], 2)];
        }

        $rideRef = $this->ride->reference_number ?? 'ETG-'.now()->format('ymd').'-'.substr($this->ride->id ?? '0000', 0, 4);

        $rideDate = $this->ride->completed_at
            ? $this->ride->completed_at->format('j F Y')
            : now()->format('j F Y');

        $paymentNote = match ($this->ride->payment_method?->value ?? 'card') {
            'card' => "Payment will be charged to {$paymentMethod}.",
            'wallet' => 'Payment will be deducted from your Etigo wallet.',
            'cash' => 'Paid in cash to your driver.',
            default => '',
        };

        return (new MailMessage)
            ->subject("Your Etigo ride receipt · {$currencySymbol}".number_format($finalFare, 2))
            ->view('emails.ride-receipt', [
                'preheader' => "Your Etigo ride receipt · {$currencySymbol}".number_format($finalFare, 2).". {$pickupArea} to {$dropoffArea} on {$rideDate}.",
                'preheaderLabel' => 'Ride receipt',
                'preheaderTitle' => "Your Etigo ride receipt · {$currencySymbol}".number_format($finalFare, 2),
                'preheaderSub' => "{$pickupArea} to {$dropoffArea} on {$rideDate}. Paid with {$paymentMethod}.",
                'subject' => "Your Etigo ride receipt · {$currencySymbol}".number_format($finalFare, 2),
                'userName' => $notifiable->first_name,
                'recipientEmail' => $notifiable->email,
                'currency' => $currency,
                'currencySymbol' => $currencySymbol,
                'totalPaid' => number_format($finalFare, 2),
                'paymentMethodLabel' => $paymentMethod,
                'pickupArea' => $pickupArea,
                'dropoffArea' => $dropoffArea,
                'pickupAddress' => $this->ride->pickup_address,
                'dropoffAddress' => $this->ride->destination_address,
                'pickupTime' => $this->ride->started_at ? $this->ride->started_at->format('H:i').' WAT' : '',
                'dropoffTime' => $this->ride->completed_at ? $this->ride->completed_at->format('H:i').' WAT' : '',
                'rideReference' => $rideRef,
                'rideDate' => $rideDate,
                'passengerName' => trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? '')),
                'driverName' => $this->ride->driver?->first_name
                    ? trim($this->ride->driver->first_name.' '.$this->ride->driver->last_name)
                    : 'Your driver',
                'vehicleType' => $this->ride->rideOption?->name ?? 'Etigo',
                'duration' => $this->fareDetails['duration_minutes'].' min',
                'distance' => $this->fareDetails['distance_km'].' km',
                'fareItems' => $fareItems,
                'paymentNote' => $paymentNote,
                'mapImageUrl' => $this->mapImageUrl ?? $this->buildMapUrl(),
                'receiptUrl' => config('app.frontend_url', config('app.url')).'/rides/'.$this->ride->id,
            ]);
    }

    private function buildMapUrl(): ?string
    {
        $token = config('services.mapbox.public_token');

        if (! $token || ! $this->ride->pickup_lat || ! $this->ride->destination_lat) {
            return null;
        }

        $pickupMarker = "pin-s-a+0d7c3d({$this->ride->pickup_lng},{$this->ride->pickup_lat})";
        $dropoffMarker = "pin-s-b+18181b({$this->ride->destination_lng},{$this->ride->destination_lat})";

        return "https://api.mapbox.com/styles/v1/mapbox/light-v11/static/{$pickupMarker},{$dropoffMarker}/auto/506x250@2x?padding=50&access_token={$token}";
    }

    private function extractArea(string $address): string
    {
        $parts = explode(',', $address);

        return trim($parts[0]);
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
