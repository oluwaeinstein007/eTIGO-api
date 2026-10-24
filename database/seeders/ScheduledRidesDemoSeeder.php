<?php

namespace Database\Seeders;

use App\Enums\BalanceDueReason;
use App\Enums\CancellationReason;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\FareEstimationService;
use App\Services\PassengerBalanceService;
use Database\Seeders\Concerns\SeedsDemoActivity;
use Database\Seeders\Data\NigerianCities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bookings for later in Lagos and Abuja, one in each state the passenger app
 * and the admin Scheduled Rides screen show:
 *
 * - Ade: airport run tomorrow at 05:30, reserved by Kemi.
 * - Ngozi: Surulere to Victoria Island in three days, paid from the wallet.
 * - Emeka: Maitama to the airport in two days, reserved by Segun.
 * - Funmi: yesterday's 07:00 booking, dispatched and completed.
 * - An Abuja rider whose wallet booking could not be dispatched (balance too low).
 * - An Abuja rider who cancelled inside the late-cancellation window and
 *   owes the fee (only when the fee is switched on).
 *
 * Upcoming bookings are dispatched at their time like any other (on staging
 * that means real matching); cancel them first if that is not wanted.
 *
 * php artisan db:seed --class=ScheduledRidesDemoSeeder
 */
class ScheduledRidesDemoSeeder extends Seeder
{
    use SeedsDemoActivity;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping ScheduledRidesDemoSeeder in production.');

            return;
        }

        $lagos = City::where('slug', 'lagos')->first();
        $abuja = City::where('slug', 'abuja')->first();
        $users = User::whereIn('email', ['ade@demo.etigo.com', 'ngozi@demo.etigo.com', 'emeka@demo.etigo.com', 'funmi@demo.etigo.com', 'kemi@demo.etigo.com', 'segun@demo.etigo.com', 'bayo@demo.etigo.com'])
            ->get()->keyBy(fn (User $user) => Str::before($user->email, '@'));
        $abujaRiders = User::where('email', 'like', '%.abuja@demo.etigo.com')->orderBy('email')->get();
        $classes = VehicleClass::all()->keyBy('name');

        if (! $lagos || ! $abuja || $users->count() < 7 || $abujaRiders->count() < 2) {
            $this->command?->warn('Run DemoSeeder and CityActivityDemoSeeder first.');

            return;
        }

        // Already seeded: a booking by one of these riders. Other bookings on
        // the database do not count.
        $riderIds = [...$users->only(['ade', 'ngozi', 'emeka', 'funmi'])->pluck('id'), ...$abujaRiders->take(2)->pluck('id')];

        if (ScheduledRide::whereHas('ride', fn ($q) => $q->whereIn('passenger_id', $riderIds))->exists()) {
            return;
        }

        // One transaction: a failure leaves nothing behind, so a rerun starts clean.
        DB::transaction(fn () => $this->seedBookings($lagos, $abuja, $users, $abujaRiders, $classes));
    }

    /**
     * @param  Collection<string, User>  $users
     * @param  Collection<int, User>  $abujaRiders
     * @param  Collection<string, VehicleClass>  $classes
     */
    private function seedBookings(City $lagos, City $abuja, Collection $users, Collection $abujaRiders, Collection $classes): void
    {
        $lead = AppSetting::scheduledRides()['dispatch_lead_minutes'];

        $this->booking($lagos, $this->classFor($lagos, 'comfort', $classes), $users['ade'], ['lagos', 'Admiralty Way'], ['lagos', 'Murtala Muhammed'],
            now()->addDay()->setTime(5, 30), PaymentMethod::Card, bookedAt: now()->subHours(9),
            scheduled: ['reserved_driver_id' => $users['kemi']->id, 'reserved_at' => now()->subHours(2)]);

        $this->booking($lagos, $this->classFor($lagos, 'economy', $classes), $users['ngozi'], ['lagos', 'Bode Thomas'], ['lagos', 'Adeola Odeku'],
            now()->addDays(3)->setTime(8, 0), PaymentMethod::Wallet, bookedAt: now()->subHours(3));

        $this->booking($abuja, $this->classFor($abuja, 'premium', $classes), $users['emeka'], ['abuja', 'Transcorp Hilton'], ['abuja', 'Nnamdi Azikiwe'],
            now()->addDays(2)->setTime(6, 0), PaymentMethod::Card, bookedAt: now()->subDay(),
            scheduled: ['reserved_driver_id' => $users['segun']->id, 'reserved_at' => now()->subHours(20)]);

        // Yesterday's 07:00 booking: dispatched on time and completed.
        $pickupAt = now()->subDay()->setTime(7, 0);
        $ride = $this->trip([
            'city' => $lagos,
            'tier' => 'A',
            'class' => $this->classFor($lagos, 'economy', $classes),
            'passenger' => $users['funmi'],
            'driver' => $users['bayo'],
            'from' => $this->place('lagos', 'Bode Thomas'),
            'to' => $this->place('lagos', 'Tafawa Balewa'),
            'requested_at' => $pickupAt->copy()->subMinutes($lead),
            'status' => 'completed',
            'payment' => PaymentMethod::Card,
            'rating' => [5, 'Booked the night before and the driver was there early.'],
        ]);
        ScheduledRide::create([
            'ride_id' => $ride->id,
            'scheduled_pickup_at' => $pickupAt,
            'reminder_sent_at' => $pickupAt->copy()->subHour(),
            'dispatched_at' => $pickupAt->copy()->subMinutes($lead),
        ])->forceFill(['created_at' => $pickupAt->copy()->subHours(14)])->saveQuietly();

        // A wallet booking whose balance had run down by dispatch time.
        $failed = $this->booking($abuja, $this->classFor($abuja, 'economy', $classes), $abujaRiders[0], ['abuja', 'Life Camp'], ['abuja', 'Wuse Market'],
            now()->subDays(2)->setTime(18, 30), PaymentMethod::Wallet, bookedAt: now()->subDays(3),
            scheduled: ['dispatch_failure_reason' => 'insufficient_wallet_balance', 'cancelled_at' => now()->subDays(2)->setTime(18, 30 - $lead)]);
        $failed->update(['status' => RideStatus::Cancelled, 'cancellation_reason' => CancellationReason::ScheduledDispatchFailed->value, 'cancellation_details' => 'Your wallet balance was too low to pay for this ride when we started looking for a driver.']);

        $this->lateCancellation($abuja, $this->classFor($abuja, 'economy', $classes), $abujaRiders[1]);
    }

    /**
     * The class to book: the one asked for when the city offers and prices
     * it, else the first standard class it does (a city set up by hand, as
     * on staging, may not offer Economy).
     *
     * @param  Collection<string, VehicleClass>  $classes
     */
    private function classFor(City $city, string $preferred, Collection $classes): VehicleClass
    {
        foreach (array_unique([$preferred, 'economy', 'comfort', 'premium', 'suv']) as $name) {
            $class = $classes->get($name);

            if ($class
                && $city->vehicleClasses()->wherePivot('is_active', true)->where('vehicle_classes.id', $class->id)->exists()
                && PricingConfig::currentFor($city->id, $class->id)) {
                return $class;
            }
        }

        throw new \RuntimeException("{$city->name} offers no priced standard vehicle class.");
    }

    /**
     * @param  array{0: string, 1: string}  $from
     * @param  array{0: string, 1: string}  $to
     * @param  array<string, mixed>  $scheduled
     */
    private function booking(City $city, VehicleClass $class, User $passenger, array $from, array $to, Carbon $pickupAt, PaymentMethod $method, Carbon $bookedAt, array $scheduled = []): Ride
    {
        $from = $this->place(...$from);
        $to = $this->place(...$to);
        $quote = $this->at($bookedAt, fn () => $this->quote($city, $class, $from, $to, 'A'));
        $reservationFee = FareEstimationService::reservationFee();

        $ride = $this->at($bookedAt, fn () => Ride::create([
            'city_id' => $city->id,
            'vehicle_class_id' => $class->id,
            'passenger_id' => $passenger->id,
            'pickup_lat' => $from[0],
            'pickup_lng' => $from[1],
            'pickup_address' => $from[2],
            'destination_lat' => $to[0],
            'destination_lng' => $to[1],
            'destination_address' => $to[2],
            'status' => RideStatus::Scheduled,
            'share_token' => Str::random(32),
            'fare_estimate_amount' => $quote['fare'] + $reservationFee,
            'fare_currency' => 'NGN',
            'pricing_snapshot' => [...$quote['snapshot'], 'reservation_fee' => $reservationFee],
            'payment_method' => $method,
            'payment_status' => PaymentStatus::Pending->value,
        ]));

        $this->at($bookedAt, fn () => ScheduledRide::create(['ride_id' => $ride->id, 'scheduled_pickup_at' => $pickupAt, ...$scheduled]));

        return $ride;
    }

    private function lateCancellation(City $city, VehicleClass $class, User $passenger): void
    {
        $settings = AppSetting::scheduledRides();
        $fee = $settings['late_cancellation_fee'];

        // Cancelled halfway through the fee window, so the fee applies
        // whatever the window is set to.
        $minutesBefore = intdiv($settings['late_cancellation_window_minutes'], 2);

        if ($fee <= 0 || $minutesBefore < 1) {
            return;
        }

        $pickupAt = now()->subDay()->setTime(9, 0);
        $cancelledAt = $pickupAt->copy()->subMinutes($minutesBefore);

        $ride = $this->booking($city, $class, $passenger, ['abuja', 'Area 11'], ['abuja', 'Ceddi Plaza'], $pickupAt, PaymentMethod::Cash,
            bookedAt: $pickupAt->copy()->subDays(2),
            scheduled: [
                'cancelled_at' => $cancelledAt,
                'late_cancellation_fee' => $fee,
                'late_cancellation_fee_status' => 'balance_due',
                'late_cancellation_fee_note' => "Cancelled {$minutesBefore} minutes before pickup, inside the {$settings['late_cancellation_window_minutes']}-minute window.",
            ]);

        $ride->update([
            'status' => RideStatus::Cancelled,
            'cancelled_by' => $passenger->id,
            'cancellation_reason' => CancellationReason::ChangedMind->value,
        ]);

        $this->at($cancelledAt, fn () => app(PassengerBalanceService::class)->record($passenger->id, $ride, BalanceDueReason::LateCancellationFee, (int) round($fee * 100), null));
    }

    /**
     * @return array{0: float, 1: float, 2: string}
     */
    private function place(string $slug, string $label): array
    {
        return collect(NigerianCities::find($slug)['landmarks'])->first(fn (array $landmark) => str_starts_with($landmark[2], $label));
    }
}
