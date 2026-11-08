<?php

namespace App\Enums;

/**
 * What a RideRefund row refunded: the ride's fare (counts against
 * WalletRefundService's remaining-refundable fare balance) or a scheduled
 * ride's late-cancellation fee (counts against a separate balance, tracked
 * by ScheduledRideCancellationFeeService).
 */
enum RideRefundCategory: string
{
    case Fare = 'fare';
    case CancellationFee = 'cancellation_fee';
}
