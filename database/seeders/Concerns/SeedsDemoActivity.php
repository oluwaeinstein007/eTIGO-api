<?php

namespace Database\Seeders\Concerns;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Enums\WalletTransactionStatus;
use App\Jobs\CalculateCarbonScoreJob;
use App\Models\Account;
use App\Models\City;
use App\Models\Payment;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;
use App\Models\WalletTransaction;
use App\Services\CityDetectionService;
use App\Services\DriverEarningsService;
use App\Services\FareEstimationService;
use App\Services\LedgerService;
use App\Services\WalletPaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helpers for demo seeders that need money and trips to look real: fares
 * from the city's pricing, top-ups and settlements posted through the ledger
 * (so ledger:check stays clean), and each step stamped at the time it would
 * have happened.
 */
trait SeedsDemoActivity
{
    /**
     * Run with the clock set to $at, so services stamp the past correctly.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function at(Carbon $at, callable $callback): mixed
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at->toImmutable());

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * Road distance (straight line x 1.35) and drive time at the city's
     * typical speed: Lagos and Abuja traffic is slowest.
     *
     * @return array{distance_km: float, duration_minutes: float}
     */
    protected function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $tier): array
    {
        $km = app(CityDetectionService::class)->haversineDistance($fromLat, $fromLng, $toLat, $toLng) * 1.35;
        $kmPerHour = match ($tier) {
            'A' => 19.0,
            'B' => 24.0,
            default => 28.0,
        };

        return [
            'distance_km' => round(max($km, 1.2), 2),
            'duration_minutes' => round(max(4.0, $km / $kmPerHour * 60), 1),
        ];
    }

    /**
     * Credit a wallet the way a successful Paystack top-up does.
     */
    protected function topUp(User $user, int $amountKobo, Carbon $at): WalletTransaction
    {
        return $this->at($at, function () use ($user, $amountKobo) {
            $ledger = app(LedgerService::class);
            $wallet = $ledger->findOrCreateAccount($user->getMorphClass(), $user->id, AccountType::PassengerWallet);
            $reference = 'TOPUP-'.strtoupper(Str::random(12));

            $transaction = WalletTransaction::create([
                'account_id' => $wallet->id,
                'reference' => $reference,
                'amount' => $amountKobo,
                'save_card' => false,
                'status' => WalletTransactionStatus::Pending,
                'gateway_transaction_id' => (string) random_int(4_000_000_000, 4_999_999_999),
            ]);

            $journal = $ledger->postJournal([
                ['account_id' => $ledger->systemAccount(AccountType::PspClearing)->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $amountKobo],
                ['account_id' => $wallet->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $amountKobo],
            ], [
                'description' => "Wallet top-up via Paystack: {$reference}",
                'idempotency_key' => "topup-{$reference}",
                'metadata' => ['reference' => $reference, 'amount_kobo' => $amountKobo],
            ]);

            $transaction->update([
                'status' => WalletTransactionStatus::Completed,
                'journal_id' => $journal->id,
                'completed_at' => now(),
            ]);

            return $transaction;
        });
    }

    protected function walletBalance(User $user): int
    {
        return (int) Account::where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->id)
            ->where('type', AccountType::PassengerWallet)
            ->value('balance');
    }

    /**
     * A trip from request to its end state, priced from the city's pricing
     * and settled through the same services as a real one.
     *
     * $trip: city, tier, class, passenger, driver (user, or null when no
     * driver was found), from and to ([lat, lng, label]), requested_at,
     * status (completed, cancelled, no_driver_found), payment, and optional
     * cancelled_by ('passenger' or 'driver'), cancellation_reason, tip_kobo,
     * rating ([score, comment]), driver_rating, rider (third party
     * [name, phone]).
     *
     * @param  array<string, mixed>  $trip
     */
    protected function trip(array $trip): ?Ride
    {
        /** @var City $city */
        $city = $trip['city'];
        /** @var VehicleClass $class */
        $class = $trip['class'];
        /** @var Carbon $requestedAt */
        $requestedAt = $trip['requested_at']->copy();
        $status = RideStatus::from($trip['status']);
        $method = $trip['payment'];

        $quote = $this->at($requestedAt, fn () => $this->quote($city, $class, $trip['from'], $trip['to'], $trip['tier']));

        if (! $quote) {
            return null;
        }

        [$fromLat, $fromLng, $fromLabel] = $trip['from'];
        [$toLat, $toLng, $toLabel] = $trip['to'];
        ['fare' => $fare, 'route' => $route, 'snapshot' => $snapshot] = $quote;

        // A wallet that cannot cover the fare pays cash, as the app would
        // have refused the wallet at booking.
        if ($method === PaymentMethod::Wallet && $status === RideStatus::Completed
            && app(WalletPaymentService::class)->availableBalance($trip['passenger']) < (int) round($fare * 100)) {
            $method = PaymentMethod::Cash;
        }

        $matchedAt = $requestedAt->copy()->addSeconds(random_int(40, 150));
        $arrivedAt = $matchedAt->copy()->addMinutes(random_int(3, 9));
        $startedAt = $arrivedAt->copy()->addMinutes(random_int(1, 4));
        $completedAt = $startedAt->copy()->addMinutes((int) round($route['duration_minutes'] * random_int(90, 115) / 100));

        $endAt = match ($status) {
            RideStatus::Completed => $completedAt,
            RideStatus::NoDriverFound => $requestedAt->copy()->addMinutes(5),
            default => $matchedAt->copy()->addMinutes(random_int(1, 6)),
        };

        $ride = $this->at($requestedAt, fn () => Ride::create([
            'city_id' => $city->id,
            'vehicle_class_id' => $class->id,
            'passenger_id' => $trip['passenger']->id,
            'is_third_party' => isset($trip['rider']),
            'rider_name' => $trip['rider'][0] ?? null,
            'rider_phone' => $trip['rider'][1] ?? null,
            'pin_recipient' => isset($trip['rider']) ? 'rider' : null,
            'tracking_recipient' => isset($trip['rider']) ? 'both' : null,
            'driver_id' => $status === RideStatus::NoDriverFound ? null : $trip['driver']?->id,
            'pickup_lat' => $fromLat,
            'pickup_lng' => $fromLng,
            'pickup_address' => $fromLabel,
            'destination_lat' => $toLat,
            'destination_lng' => $toLng,
            'destination_address' => $toLabel,
            'status' => $status,
            'share_token' => Str::random(32),
            'fare_estimate_amount' => $fare,
            'final_fare_amount' => $status === RideStatus::Completed ? $fare : null,
            'fare_currency' => 'NGN',
            'pricing_snapshot' => $snapshot,
            'payment_method' => $method,
            'payment_status' => PaymentStatus::Pending->value,
            'cancelled_by' => match ($trip['cancelled_by'] ?? null) {
                'passenger' => $trip['passenger']->id,
                'driver' => $trip['driver']?->id,
                default => null,
            },
            'cancellation_reason' => $trip['cancellation_reason'] ?? null,
            'matched_at' => $status === RideStatus::NoDriverFound ? null : $matchedAt,
            'started_at' => $status === RideStatus::Completed ? $startedAt : null,
            'completed_at' => $status === RideStatus::Completed ? $completedAt : null,
        ]));

        $ride->forceFill(['created_at' => $requestedAt, 'updated_at' => $endAt])->saveQuietly();

        $this->transitions($ride, $trip, $status, compact('requestedAt', 'matchedAt', 'arrivedAt', 'startedAt', 'completedAt', 'endAt'));

        if ($status === RideStatus::Completed) {
            $this->settle($ride, $method, $fare, (int) ($trip['tip_kobo'] ?? 0), $completedAt);
            $this->rate($ride, $trip, $completedAt);
            $this->at($completedAt->copy()->addMinutes(2), fn () => app()->call([new CalculateCarbonScoreJob($ride->id), 'handle']));
        }

        return $ride->refresh();
    }

    /**
     * The upfront fare between two places, from the city's pricing in
     * effect now, with the snapshot a booking stores. Null when the class
     * is not priced in the city.
     *
     * @param  array{0: float, 1: float, 2: string}  $from
     * @param  array{0: float, 1: float, 2: string}  $to
     * @return array{fare: float, route: array{distance_km: float, duration_minutes: float}, snapshot: array<string, mixed>}|null
     */
    protected function quote(City $city, VehicleClass $class, array $from, array $to, string $tier): ?array
    {
        $pricing = PricingConfig::currentFor($city->id, $class->id);

        if (! $pricing) {
            return null;
        }

        $route = $this->route($from[0], $from[1], $to[0], $to[1], $tier);
        $fares = app(FareEstimationService::class);
        $fare = $fares->applyAdjustments($pricing, $fares->calculateFare($pricing, $route['distance_km'], $route['duration_minutes']), 1.0)['fare'];

        return [
            'fare' => $fare,
            'route' => $route,
            'snapshot' => [
                ...$pricing->toSnapshot(),
                'distance_km' => $route['distance_km'],
                'duration_minutes' => $route['duration_minutes'],
                'surge_multiplier' => 1.0,
                'surge_rule_name' => null,
                'booking_fee_discount_pct' => 0.0,
                'tier_name' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $trip
     * @param  array<string, Carbon>  $times
     */
    private function transitions(Ride $ride, array $trip, RideStatus $status, array $times): void
    {
        $passengerId = $trip['passenger']->id;
        $driverId = $trip['driver']?->id;

        $steps = [[null, 'requested', 'passenger', $passengerId, $times['requestedAt']]];
        $steps[] = ['requested', 'searching', 'system', null, $times['requestedAt']->copy()->addSeconds(2)];

        if ($status === RideStatus::NoDriverFound) {
            $steps[] = ['searching', 'no_driver_found', 'system', null, $times['endAt']];
        } else {
            $steps[] = ['searching', 'matched', 'system', null, $times['matchedAt']];

            if ($status === RideStatus::Cancelled) {
                $byDriver = ($trip['cancelled_by'] ?? null) === 'driver';
                $steps[] = ['matched', 'cancelled', $byDriver ? 'driver' : 'passenger', $byDriver ? $driverId : $passengerId, $times['endAt']];
            } else {
                $steps[] = ['matched', 'driver_en_route', 'driver', $driverId, $times['matchedAt']->copy()->addSeconds(20)];
                $steps[] = ['driver_en_route', 'driver_arrived', 'driver', $driverId, $times['arrivedAt']];
                $steps[] = ['driver_arrived', 'in_progress', 'driver', $driverId, $times['startedAt']];
                $steps[] = ['in_progress', 'completed', 'driver', $driverId, $times['completedAt']];
            }
        }

        DB::table('ride_state_transitions')->insert(array_map(fn (array $step) => [
            'id' => (string) Str::uuid(),
            'ride_id' => $ride->id,
            'from_state' => $step[0],
            'to_state' => $step[1],
            'triggered_by_type' => $step[2],
            'triggered_by_id' => $step[3],
            'metadata' => json_encode([]),
            'created_at' => $step[4],
        ], $steps));
    }

    private function settle(Ride $ride, PaymentMethod $method, float $fare, int $tipKobo, Carbon $completedAt): void
    {
        $fareKobo = (int) round($fare * 100);

        $this->at($completedAt, function () use ($ride, $method, $fare, $fareKobo, $tipKobo) {
            $status = $method === PaymentMethod::Cash ? PaymentStatus::Collected : PaymentStatus::Captured;

            if ($method === PaymentMethod::Wallet) {
                $wallet = app(WalletPaymentService::class);
                $wallet->placeHold($ride->passenger, $ride, $fareKobo);
                $wallet->settle($ride, $fareKobo);
            } else {
                app(DriverEarningsService::class)->creditRideEarnings($ride, $fareKobo);
            }

            // Card tips go to the driver in full and wallet tips move from the
            // wallet to the driver, both through the ledger; cash tips are
            // handed over. A wallet that cannot cover the tip gives none.
            if ($tipKobo > 0 && $method === PaymentMethod::Card) {
                app(DriverEarningsService::class)->creditTip($ride, $tipKobo);
            } elseif ($tipKobo > 0 && $method === PaymentMethod::Wallet) {
                if (app(WalletPaymentService::class)->availableBalance($ride->passenger) >= $tipKobo) {
                    app(WalletPaymentService::class)->payTip($ride, $tipKobo);
                } else {
                    $tipKobo = 0;
                }
            }

            Payment::create([
                'ride_id' => $ride->id,
                'amount' => $fare,
                'currency' => 'NGN',
                'method' => $method,
                'gateway_transaction_id' => $method === PaymentMethod::Card ? (string) random_int(4_000_000_000, 4_999_999_999) : null,
                'tip_amount' => $tipKobo / 100,
                'tip_method' => $tipKobo > 0 ? $method : null,
                'status' => $status->value,
            ]);

            $ride->forceFill(['payment_status' => $status->value])->saveQuietly();
        });
    }

    /**
     * @param  array<string, mixed>  $trip
     */
    private function rate(Ride $ride, array $trip, Carbon $completedAt): void
    {
        $rows = [];

        if (isset($trip['rating'])) {
            $rows[] = [$ride->passenger_id, $ride->driver_id, $trip['rating'][0], $trip['rating'][1], $completedAt->copy()->addMinutes(random_int(2, 40))];
        }

        if (isset($trip['driver_rating'])) {
            $rows[] = [$ride->driver_id, $ride->passenger_id, $trip['driver_rating'], null, $completedAt->copy()->addMinutes(random_int(1, 10))];
        }

        foreach ($rows as [$by, $for, $score, $comment, $at]) {
            DB::table('ratings')->insert([
                'id' => (string) Str::uuid(),
                'ride_id' => $ride->id,
                'rated_by_user_id' => $by,
                'rated_user_id' => $for,
                'score' => $score,
                'comment' => $comment,
                'created_at' => $at,
            ]);
        }
    }
}
