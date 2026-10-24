<?php

namespace App\Http\Requests\Ride;

use App\Enums\PaymentMethod;
use App\Rules\CashPaymentsAllowed;
use App\Services\CityDetectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateScheduledRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Send only what changes. A new place needs its coordinates and address
     * together.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scheduled_pickup_at' => ['sometimes', 'required', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'],
            'pickup_lat' => ['required_with:pickup_lng,pickup_address', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required_with:pickup_lat,pickup_address', 'numeric', 'between:-180,180'],
            'pickup_address' => ['required_with:pickup_lat,pickup_lng', 'string', 'max:500'],
            'destination_lat' => ['required_with:destination_lng,destination_address', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required_with:destination_lat,destination_address', 'numeric', 'between:-180,180'],
            'destination_address' => ['required_with:destination_lat,destination_lng', 'string', 'max:500'],
            'payment_method' => ['sometimes', Rule::enum(PaymentMethod::class), new CashPaymentsAllowed],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_pickup_at.regex' => 'The scheduled pickup time must include a timezone offset, e.g. 2026-10-20T07:30:00+01:00.',
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

                if ($this->safe()->collect()->isEmpty()) {
                    $validator->errors()->add('ride', 'Send at least one field to change.');

                    return;
                }

                // A new pickup must stay in the booking's city: its prices
                // and drivers were fixed when it was booked.
                $ride = $this->route('ride');

                if ($this->filled('pickup_lat') && $ride?->city_id) {
                    $city = app(CityDetectionService::class)->pickupCity($ride->city_id, (float) $this->input('pickup_lat'), (float) $this->input('pickup_lng'));

                    if ($city !== null && $city->id !== $ride->city_id) {
                        $validator->errors()->add('pickup_lat', $city->is_active
                            ? "This pickup is in {$city->name}, not the booking's city. Cancel and book again to be picked up there."
                            : $city->launch_status->unavailableMessage($city->name));
                    }
                }
            },
        ];
    }
}
