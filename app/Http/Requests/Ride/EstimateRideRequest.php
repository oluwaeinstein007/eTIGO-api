<?php

namespace App\Http\Requests\Ride;

use App\Models\City;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EstimateRideRequest extends FormRequest
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

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $city = City::find($this->input('city_id'));

                if ($city && ! $city->is_active) {
                    $validator->errors()->add('city_id', $city->launch_status->unavailableMessage($city->name));
                }

                $pickupLat = $this->float('pickup_lat');
                $pickupLng = $this->float('pickup_lng');
                $destLat = $this->float('destination_lat');
                $destLng = $this->float('destination_lng');

                if ($pickupLat === $destLat && $pickupLng === $destLng) {
                    $validator->errors()->add('destination_lat', 'Pickup and destination cannot be the same location.');
                }
            },
        ];
    }
}
