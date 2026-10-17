<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\RiderNotificationStatus;
use App\Enums\RideStatus;
use App\Enums\ThirdPartyRecipient;
use App\Models\AppSetting;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Booking rules for rides a passenger (the booker) books for someone else
 * (Phase 2, BE2-3P-03). The booker stays the ride's passenger: they pay,
 * cancel, rate and see the trip in their history. The rider only gets
 * WhatsApp/SMS messages (ThirdPartyRoutingService).
 */
class ThirdPartyBookingService
{
    /**
     * Put a phone number in E.164 form. Nigerian local numbers
     * (0803 123 4567) and numbers without the + are accepted.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/[\s\-().]/', '', trim($phone));

        if ($digits === '') {
            return null;
        }

        return match (true) {
            str_starts_with($digits, '+') => $digits,
            str_starts_with($digits, '00') => '+'.substr($digits, 2),
            preg_match('/^0\d{10}$/', $digits) === 1 => '+234'.substr($digits, 1),
            preg_match('/^234\d{10}$/', $digits) === 1 => '+'.$digits,
            default => $digits,
        };
    }

    /**
     * Check a third-party booking and return the ride attributes to save, or
     * null when the ride is the booker's own (no rider phone, or the rider
     * phone is the booker's). Call it with the booker's row locked so two
     * bookings sent together cannot both pass the limits.
     *
     * @return array{is_third_party: true, rider_name: string, rider_phone: string, pin_recipient: string, tracking_recipient: string, rider_notification_status: string|null}|null
     *
     * @throws ValidationException
     */
    public function prepare(
        User $booker,
        ?string $riderName,
        ?string $riderPhone,
        bool $ageConfirmed,
        PaymentMethod $paymentMethod,
        bool $scheduled,
    ): ?array {
        $riderPhone = self::normalizePhone($riderPhone);

        if ($riderPhone === null || $riderPhone === $booker->phone) {
            return null;
        }

        $policy = AppSetting::thirdParty();

        if (! $policy['enabled']) {
            throw ValidationException::withMessages([
                'rider_phone' => ['Booking a ride for someone else is not available right now.'],
            ]);
        }

        if (trim((string) $riderName) === '') {
            throw ValidationException::withMessages([
                'rider_name' => ["Enter the rider's name."],
            ]);
        }

        if (! $ageConfirmed) {
            throw ValidationException::withMessages([
                'rider_age_confirmed' => ['Confirm that the rider is 18 or over, or that an adult is travelling with them.'],
            ]);
        }

        if (! $this->paymentMethodAllowed($paymentMethod, $policy)) {
            throw ValidationException::withMessages([
                'payment_method' => ["{$paymentMethod->label()} can't be used when you book for someone else. Pay by wallet or card."],
            ]);
        }

        // A booking for later is not on the road yet; the scheduled-ride
        // limits cover it until dispatch.
        if (! $scheduled) {
            $this->assertUnderActiveLimit($booker, 'rider_phone', $policy);
        }

        // Cancelled bookings count too, so booking and cancelling cannot be
        // used to message many numbers.
        $recentNumbers = Ride::where('passenger_id', $booker->id)
            ->thirdParty()
            ->where('created_at', '>=', now()->subDay())
            ->distinct()
            ->pluck('rider_phone');

        if (! $recentNumbers->contains($riderPhone) && $recentNumbers->count() >= $policy['max_rider_numbers_per_day']) {
            throw ValidationException::withMessages([
                'rider_phone' => ["You can book for at most {$policy['max_rider_numbers_per_day']} different people in 24 hours."],
            ]);
        }

        $messagesRider = ThirdPartyRecipient::from($policy['pin_recipient'])->includesRider()
            || ThirdPartyRecipient::from($policy['tracking_recipient'])->includesRider();

        return [
            'is_third_party' => true,
            'rider_name' => trim((string) $riderName),
            'rider_phone' => $riderPhone,
            'pin_recipient' => $policy['pin_recipient'],
            'tracking_recipient' => $policy['tracking_recipient'],
            // Nothing is sent to the rider under a booker-only policy.
            'rider_notification_status' => $messagesRider ? RiderNotificationStatus::Pending->value : null,
        ];
    }

    /**
     * Refuse another third-party ride on the road when the booker already
     * has the maximum. Also checked when a ride that found no driver is sent
     * out again ($retrying), which is not counted while it waits.
     *
     * @param  array{max_active_per_booker: int}|null  $policy
     *
     * @throws ValidationException
     */
    public function assertUnderActiveLimit(User $booker, string $field, ?array $policy = null, ?Ride $retrying = null): void
    {
        $limit = ($policy ?? AppSetting::thirdParty())['max_active_per_booker'];

        $active = Ride::where('passenger_id', $booker->id)
            ->thirdParty()
            ->whereIn('status', RideStatus::activeStatuses())
            ->when($retrying, fn ($query) => $query->whereKeyNot($retrying->id))
            ->count();

        if ($active >= $limit) {
            throw ValidationException::withMessages([
                $field => ["You can have at most {$limit} rides for other people at the same time."],
            ]);
        }
    }

    /**
     * Whether a third-party booking may be paid this way under the current
     * policy. Wallet and card are always allowed.
     *
     * @param  array{allow_cash: bool, allow_bank_transfer: bool}|null  $policy
     */
    public function paymentMethodAllowed(PaymentMethod $method, ?array $policy = null): bool
    {
        $policy ??= AppSetting::thirdParty();

        return match ($method) {
            PaymentMethod::Cash => $policy['allow_cash'],
            PaymentMethod::BankTransfer => $policy['allow_bank_transfer'],
            default => true,
        };
    }
}
