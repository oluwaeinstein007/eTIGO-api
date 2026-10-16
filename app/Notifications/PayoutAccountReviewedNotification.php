<?php

namespace App\Notifications;

use App\Enums\BankAccountReviewStatus;
use App\Enums\NotificationType;
use App\Models\BankAccount;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells the driver the outcome of E-tiGo's check of a payout account whose
 * name did not match their verified name.
 */
class PayoutAccountReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly BankAccount $bankAccount,
    ) {}

    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        $lastFour = substr($this->bankAccount->account_number, -4);
        $approved = $this->bankAccount->review_status === BankAccountReviewStatus::Approved;

        return [
            'type' => NotificationType::PayoutAccountReviewed->value,
            'title' => NotificationType::PayoutAccountReviewed->label(),
            'body' => $approved
                ? "Your bank account ending {$lastFour} has been approved for payouts."
                : "We could not approve your bank account ending {$lastFour} for payouts"
                    .($this->bankAccount->review_note ? ': '.rtrim($this->bankAccount->review_note, '. ') : '')
                    .'. Add a bank account in your own name to receive payouts.',
            'bank_account_id' => $this->bankAccount->id,
            'review_status' => $this->bankAccount->review_status?->value,
        ];
    }
}
