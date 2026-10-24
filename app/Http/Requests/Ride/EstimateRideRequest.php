<?php

namespace App\Http\Requests\Ride;

use App\Models\City;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EstimateRideRequest extends FormRequest
{
    private ?City $pickupCity = null;

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
            // Optional: the server quotes the city the pickup lies in
            // (pickupCity). Used only for a pickup outside every drawn city.
            'city_id' => ['nullable', 'uuid', 'exists:cities,id'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            // Quote a scheduled ride: surge is evaluated for this pickup time.
            'scheduled_pickup_at' => [...StoreRideRequest::pickupTimeRules(), 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_pickup_at.regex' => 'The scheduled pickup time must include a timezone offset, e.g. 2026-10-20T07:30:00+01:00.',
        ];
    }

    /**
     * The city the estimate is for, decided from the pickup. Set once
     * validation has passed.
     */
    public function city(): City
    {
        return $this->pickupCity;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $pickupLat = $this->float('pickup_lat');
                $pickupLng = $this->float('pickup_lng');
                $destLat = $this->float('destination_lat');
                $destLng = $this->float('destination_lng');

                if ($pickupLat === $destLat && $pickupLng === $destLng) {
                    $validator->errors()->add('destination_lat', 'Pickup and destination cannot be the same location.');
                }

                $this->pickupCity = StoreRideRequest::resolvePickupCity($validator, $this->input('city_id'), $pickupLat, $pickupLng);
            },
        ];
    }
}
