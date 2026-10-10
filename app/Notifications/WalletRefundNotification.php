<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WalletRefundNotification extends Notification implements ShouldQueue
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
            'type' => NotificationType::WalletRefund->value,
            'title' => 'Wallet Refund',
            'body' => "₦{$amountNaira} has been refunded to your wallet.",
            'ride_id' => $this->rideId,
            'amount_kobo' => $this->amountKobo,
        ];
    }
}
