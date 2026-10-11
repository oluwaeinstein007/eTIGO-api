<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CashChangeWalletCreditNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $amountKobo,
        public readonly string $rideId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $amountNaira = number_format($this->amountKobo / 100, 2);

        return [
            'type' => NotificationType::CashChangeCredit->value,
            'title' => 'Cash Change Credited',
            'body' => "₦{$amountNaira} change from your cash ride has been added to your wallet.",
            'amount_kobo' => $this->amountKobo,
            'ride_id' => $this->rideId,
        ];
    }
}
