<?php

namespace App\Enums;

/**
 * What a ledger journal was for. Stored on each journal so wallet and ledger
 * screens can label and filter money movements without parsing descriptions.
 */
enum TransactionType: string
{
    case TopUp = 'top_up';
    case RidePayment = 'ride_payment';
    case RideSettlement = 'ride_settlement';
    case Commission = 'commission';
    case Tip = 'tip';
    case Refund = 'refund';
    case CancellationFee = 'cancellation_fee';
    case BalanceDue = 'balance_due';
    case CashChange = 'cash_change';
    case EvReservationFee = 'ev_reservation_fee';
    case FleetRemittance = 'fleet_remittance';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
    case Payout = 'payout';

    /**
     * Journal idempotency-key prefixes, longest first so a more specific
     * prefix ("balance-due-unapplied-") wins over a shorter one ("balance-due-").
     *
     * @var array<string, self>
     */
    private const KEY_PREFIXES = [
        'balance-due-unapplied-' => self::TopUp,
        'scheduled-cancel-fee-' => self::CancellationFee,
        'wallet-commission-' => self::Commission,
        'card-commission-' => self::Commission,
        'cash-commission-' => self::Commission,
        'fleet-remittance-' => self::FleetRemittance,
        'ride-settlement-' => self::RidePayment,
        'card-settlement-' => self::RideSettlement,
        'settle-earnings-' => self::RideSettlement,
        'payout-debit-' => self::Payout,
        'balance-due-' => self::BalanceDue,
        'cash-change-' => self::CashChange,
        'adjustment-' => self::Adjustment,
        'wallet-tip-' => self::Tip,
        'card-tip-' => self::Tip,
        'clawback-' => self::Refund,
        'refund-' => self::Refund,
        'ev-fee-' => self::EvReservationFee,
        'topup-' => self::TopUp,
        'REV-' => self::Reversal,
    ];

    public function label(): string
    {
        return match ($this) {
            self::TopUp => 'Wallet Top-up',
            self::RidePayment => 'Ride Payment',
            self::RideSettlement => 'Ride Settlement',
            self::Commission => 'Commission',
            self::Tip => 'Driver Tip',
            self::Refund => 'Ride Refund',
            self::CancellationFee => 'Cancellation Fee',
            self::BalanceDue => 'Balance Due',
            self::CashChange => 'Cash Change Credit',
            self::EvReservationFee => 'EV Reservation Fee',
            self::FleetRemittance => 'Fleet Remittance',
            self::Adjustment => 'Manual Adjustment',
            self::Reversal => 'Reversal',
            self::Payout => 'Payout',
        };
    }

    /**
     * Classify a journal from its idempotency key. Every posting site uses a
     * fixed key prefix, so this is how journals posted before the type column
     * existed are backfilled, and the fallback when a caller passes no type.
     */
    public static function fromJournalKey(?string $idempotencyKey): ?self
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        foreach (self::KEY_PREFIXES as $prefix => $type) {
            if (str_starts_with($idempotencyKey, $prefix)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<string, self>
     */
    public static function keyPrefixes(): array
    {
        return self::KEY_PREFIXES;
    }
}
