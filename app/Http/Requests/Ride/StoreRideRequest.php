<?php

namespace App\Http\Requests\Ride;

use App\Enums\PaymentMethod;
use App\Models\City;
use App\Models\PricingConfig;
use App\Services\ScheduledRideService;
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
        ];
    }

    /**
     * @return list<string>
     */
    public static function pickupTimeRules(): array
    {
        return ['nullable', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'];
    }

    public function messages(): array
    {
        return [
            'scheduled_pickup_at.regex' => 'The scheduled pickup time must include a timezone offset, e.g. 2026-10-20T07:30:00+01:00.',
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
                    $validator->errors()->add('city_id', 'This city is not currently active.');
                }

                $pickupLat = (float) $this->input('pickup_lat');
                $pickupLng = (float) $this->input('pickup_lng');
                $destLat = (float) $this->input('destination_lat');
                $destLng = (float) $this->input('destination_lng');

                if ($pickupLat === $destLat && $pickupLng === $destLng) {
                    $validator->errors()->add('destination_lat', 'Pickup and destination cannot be the same location.');
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
                    foreach (app(ScheduledRideService::class)->pickupTimeErrors($this->user(), $pickupAt) as $error) {
                        $validator->errors()->add('scheduled_pickup_at', $error);
                    }
                }
            },
        ];
    }
}
