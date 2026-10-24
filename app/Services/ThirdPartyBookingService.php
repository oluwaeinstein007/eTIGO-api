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
     * $shareWith is the booker's "Rider only" / "Both of us" choice and decides
     * who gets the PIN. Live tracking always follows the admin policy (both by
     * default, so the booker keeps following the trip, as on Uber and Bolt),
     * which keeps sending the pre-selected choice equal to sending none. The
     * choice is ignored when operations keep PINs from riders.
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
        ?ThirdPartyRecipient $shareWith = null,
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

        $choice = in_array($shareWith?->value, self::shareWithOptions($policy), true) ? $shareWith : null;
        $pinRecipient = $choice?->value ?? $policy['pin_recipient'];
        $trackingRecipient = $policy['tracking_recipient'];

        $messagesRider = ThirdPartyRecipient::from($pinRecipient)->includesRider()
            || ThirdPartyRecipient::from($trackingRecipient)->includesRider();

        return [
            'is_third_party' => true,
            'rider_name' => trim((string) $riderName),
            'rider_phone' => $riderPhone,
            'pin_recipient' => $pinRecipient,
            'tracking_recipient' => $trackingRecipient,
            // Nothing is sent to the rider under a booker-only policy.
            'rider_notification_status' => $messagesRider ? RiderNotificationStatus::Pending->value : null,
        ];
    }

    /**
     * The "who gets the PIN" values a booker may send: none when operations
     * send PINs to bookers only (no messages to riders).
     *
     * @param  array{pin_recipient: string}  $policy
     * @return list<string>
     */
    public static function shareWithOptions(array $policy): array
    {
        return $policy['pin_recipient'] === ThirdPartyRecipient::Booker->value
            ? []
            : [ThirdPartyRecipient::Rider->value, ThirdPartyRecipient::Both->value];
    }

    /**
     * The choices as the booking screen shows them. Tracking is not part of
     * the choice, so each description is worded from the tracking policy and
     * never promises the booker a live map they won't get.
     *
     * @param  array{pin_recipient: string, tracking_recipient: string}  $policy
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function shareWithChoices(array $policy): array
    {
        $tracking = ThirdPartyRecipient::from($policy['tracking_recipient']);
        $riderLink = $tracking->includesRider() ? ' and a tracking link' : '';

        $riderOnly = "The rider gets the PIN{$riderLink} by WhatsApp or SMS. ".($tracking->includesBooker()
            ? 'You can still follow the trip in the app.'
            : "You won't see the trip's live location.");

        $both = match ($tracking) {
            ThirdPartyRecipient::Both => 'You and the rider both get the PIN and live tracking.',
            ThirdPartyRecipient::Rider => 'You and the rider both get the PIN. Only the rider gets live tracking.',
            ThirdPartyRecipient::Booker => 'You and the rider both get the PIN. Only you get live tracking.',
        };

        $choices = [
            ThirdPartyRecipient::Rider->value => ['label' => 'Rider only', 'description' => $riderOnly],
            ThirdPartyRecipient::Both->value => ['label' => 'Both of us', 'description' => $both],
        ];

        return array_map(
            fn (string $value) => ['value' => $value, ...$choices[$value]],
            self::shareWithOptions($policy),
        );
    }

    /**
     * The choice to pre-select: the admin PIN policy, or null when the
     * booker has no choice.
     *
     * @param  array{pin_recipient: string}  $policy
     */
    public static function defaultShareWith(array $policy): ?string
    {
        if (self::shareWithOptions($policy) === []) {
            return null;
        }

        return $policy['pin_recipient'] === ThirdPartyRecipient::Rider->value
            ? ThirdPartyRecipient::Rider->value
            : ThirdPartyRecipient::Both->value;
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
     * policy. Wallet and card are always allowed; cash also needs cash
     * payments switched on for everyone.
     *
     * @param  array{allow_cash: bool, allow_bank_transfer: bool}|null  $policy
     */
    public function paymentMethodAllowed(PaymentMethod $method, ?array $policy = null): bool
    {
        $policy ??= AppSetting::thirdParty();

        return match ($method) {
            PaymentMethod::Cash => $policy['allow_cash'] && AppSetting::cashPaymentsEnabled(),
            PaymentMethod::BankTransfer => $policy['allow_bank_transfer'],
            default => true,
        };
    }
}
