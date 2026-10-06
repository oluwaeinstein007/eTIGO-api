<?php

namespace App\Http\Requests\Ride;

use App\Enums\PaymentMethod;
use App\Models\City;
use Illuminate\Foundation\Http\FormRequest;
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
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'vehicle_class_id' => ['required', 'integer', 'exists:vehicle_classes,id'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['required', 'string', 'max:500'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ];
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
                    ->where('city_id', $this->input('city_id'))
                    ->where('vehicle_class_id', $this->input('vehicle_class_id'))
                    ->where('is_active', true)
                    ->exists();

                if (! $cityVehicleClass) {
                    $validator->errors()->add('vehicle_class_id', 'This vehicle class is not available in the selected city.');
                }
            },
        ];
    }
}
