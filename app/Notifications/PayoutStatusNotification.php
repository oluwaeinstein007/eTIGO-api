<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PayoutStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $amountKobo,
        public readonly NotificationType $type,
        public readonly ?string $failureReason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $amountNaira = number_format($this->amountKobo / 100, 2);

        $body = match ($this->type) {
            NotificationType::PayoutApproved => "Your payout of ₦{$amountNaira} has been approved and is being processed.",
            NotificationType::PayoutPaid => "₦{$amountNaira} has been sent to your bank account.",
            NotificationType::PayoutFailed => "Your payout of ₦{$amountNaira} failed.".($this->failureReason ? " Reason: {$this->failureReason}" : ''),
            default => "Payout update: ₦{$amountNaira}.",
        };

        return [
            'type' => $this->type->value,
            'title' => $this->type->label(),
            'body' => $body,
            'amount_kobo' => $this->amountKobo,
        ];
    }
}
