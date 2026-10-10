<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WalletTopupNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $amountKobo,
        public readonly bool $success,
        public readonly ?string $failureReason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $type = $this->success ? NotificationType::TopupSuccess : NotificationType::TopupFailed;
        $amountNaira = number_format($this->amountKobo / 100, 2);

        return [
            'type' => $type->value,
            'title' => $this->success ? 'Top-up Successful' : 'Top-up Failed',
            'body' => $this->success
                ? "Your wallet has been credited with ₦{$amountNaira}."
                : "Your top-up of ₦{$amountNaira} failed.".($this->failureReason ? " Reason: {$this->failureReason}" : ''),
            'amount_kobo' => $this->amountKobo,
        ];
    }
}
