<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WalletRidePaymentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $rideId,
        public readonly int $amountKobo,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $amountNaira = number_format($this->amountKobo / 100, 2);

        return [
            'type' => NotificationType::RideWalletPayment->value,
            'title' => 'Ride Payment',
            'body' => "₦{$amountNaira} was charged from your wallet for your ride.",
            'ride_id' => $this->rideId,
            'amount_kobo' => $this->amountKobo,
        ];
    }
}
