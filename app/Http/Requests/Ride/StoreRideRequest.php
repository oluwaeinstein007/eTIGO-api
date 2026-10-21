<?php

namespace App\Http\Requests\Ride;

use App\Enums\PaymentMethod;
use App\Enums\ThirdPartyRecipient;
use App\Models\City;
use App\Models\PricingConfig;
use App\Services\CityDetectionService;
use App\Services\ScheduledRideService;
use App\Services\ThirdPartyBookingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'city_id' => ['required', 'uuid', 'exists:cities,id'],
            'vehicle_class_id' => ['required', 'uuid', 'exists:vehicle_classes,id'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['required', 'string', 'max:500'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'promo_code' => ['nullable', 'string', 'max:50'],
            // Book for later (Phase 2): ISO 8601 with a UTC offset, e.g.
            // 2026-10-20T07:30:00+01:00. Omit to request a ride now.
            'scheduled_pickup_at' => self::pickupTimeRules(),
            // Book for someone else (Phase 2): the rider's name and phone.
            // Nigerian local numbers are accepted and stored as E.164. A
            // rider phone equal to the booker's makes it a normal ride.
            'rider_name' => ['nullable', 'string', 'max:100'],
            'rider_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'rider_age_confirmed' => ['sometimes', 'boolean'],
            // Who gets the PIN: the rider only, or the rider and the booker
            // (the booker keeps live tracking either way). Omit to use the
            // admin default. Ignored on the booker's own rides.
            'share_with' => ['nullable', 'string', Rule::in([ThirdPartyRecipient::Rider->value, ThirdPartyRecipient::Both->value])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('rider_phone') && is_string($this->input('rider_phone'))) {
            $this->merge(['rider_phone' => ThirdPartyBookingService::normalizePhone($this->input('rider_phone'))]);
        }
    }

    /**
     * The rider's phone for a booking made for someone else, or null when the
     * passenger is riding themselves.
     */
    public function riderPhone(): ?string
    {
        $phone = $this->filled('rider_phone') ? (string) $this->input('rider_phone') : null;

        return $phone !== null && $phone !== $this->user()?->phone ? $phone : null;
    }

    /**
     * @return list<string>
     */
    public static function pickupTimeRules(): array
    {
        return ['nullable', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'];
    }

    /**
     * Why the pickup cannot be booked under this city: it lies inside
     * another city's boundary (multi-city, OQ-22), so it would get that
     * city's drivers but this city's prices, and would get round a city
     * that has not opened. Null when it is fine.
     */
    public static function pickupCityError(string $cityId, float $pickupLat, float $pickupLng): ?string
    {
        $other = app(CityDetectionService::class)->conflictingCity($cityId, $pickupLat, $pickupLng);

        if ($other === null) {
            return null;
        }

        return $other->launch_status->acceptsBookings()
            ? "This pickup is in {$other->name}. Choose {$other->name} as your city to book it."
            : $other->launch_status->unavailableMessage($other->name);
    }

    public function messages(): array
    {
        return [
            'scheduled_pickup_at.regex' => 'The scheduled pickup time must include a timezone offset, e.g. 2026-10-20T07:30:00+01:00.',
            'rider_phone.regex' => "Enter the rider's phone number in international format, e.g. +2348031234567.",
        ];
    }

    public function scheduledPickupAt(): ?Carbon
    {
        return $this->filled('scheduled_pickup_at')
            ? Carbon::parse($this->input('scheduled_pickup_at'))->utc()
            : null;
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $city = City::find($this->input('city_id'));
                if ($city && ! $city->is_active) {
                    $validator->errors()->add('city_id', $city->launch_status->unavailableMessage($city->name));
                }

                $pickupLat = (float) $this->input('pickup_lat');
                $pickupLng = (float) $this->input('pickup_lng');
                $destLat = (float) $this->input('destination_lat');
                $destLng = (float) $this->input('destination_lng');

                if ($pickupLat === $destLat && $pickupLng === $destLng) {
                    $validator->errors()->add('destination_lat', 'Pickup and destination cannot be the same location.');
                }

                if ($error = self::pickupCityError($this->input('city_id'), $pickupLat, $pickupLng)) {
                    $validator->errors()->add('pickup_lat', $error);
                }

                $cityVehicleClass = DB::table('city_vehicle_classes')
                    ->join('vehicle_classes', 'vehicle_classes.id', '=', 'city_vehicle_classes.vehicle_class_id')
                    ->where('city_vehicle_classes.city_id', $this->input('city_id'))
                    ->where('city_vehicle_classes.vehicle_class_id', $this->input('vehicle_class_id'))
                    ->where('city_vehicle_classes.is_active', true)
                    ->where('vehicle_classes.is_active', true)
                    ->exists();

                if (! $cityVehicleClass) {
                    $validator->errors()->add('vehicle_class_id', 'This vehicle class is not available in the selected city.');

                    return;
                }

                if (! PricingConfig::currentFor($this->input('city_id'), $this->input('vehicle_class_id'))) {
                    $validator->errors()->add('vehicle_class_id', 'Pricing is not yet configured for this vehicle class in the selected city.');
                }

                if ($pickupAt = $this->scheduledPickupAt()) {
                    foreach (app(ScheduledRideService::class)->pickupTimeErrors($this->user(), $pickupAt, null, $this->riderPhone()) as $error) {
                        $validator->errors()->add('scheduled_pickup_at', $error);
                    }
                }
            },
        ];
    }
}
