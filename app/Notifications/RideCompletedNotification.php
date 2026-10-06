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
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $breakdown = $this->fareDetails['fare_breakdown'];
        $currency = $this->ride->fare_currency ?? 'NGN';

        return (new MailMessage)
            ->subject('Your Etigo Ride Receipt')
            ->greeting("Hi {$notifiable->first_name},")
            ->line('Thanks for riding with Etigo! Here\'s your trip summary.')
            ->line("**From:** {$this->ride->pickup_address}")
            ->line("**To:** {$this->ride->destination_address}")
            ->line("**Distance:** {$this->fareDetails['distance_km']} km")
            ->line("**Duration:** {$this->fareDetails['duration_minutes']} min")
            ->line('---')
            ->line("Base fare: {$currency} ".number_format($breakdown['base_fare'], 2))
            ->line("Distance charge: {$currency} ".number_format($breakdown['distance_charge'], 2))
            ->line("Time charge: {$currency} ".number_format($breakdown['time_charge'], 2))
            ->line("Waiting charge: {$currency} ".number_format($breakdown['waiting_charge'], 2))
            ->line("**Total: {$currency} ".number_format($this->fareDetails['final_fare'], 2).'**')
            ->line('---')
            ->line("Payment method: ".str_replace('_', ' ', $this->ride->payment_method->value))
            ->salutation('Safe travels!');
    }
}
