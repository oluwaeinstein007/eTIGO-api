<?php

namespace App\Http\Requests\EvStation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreChargeRequestFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'battery_level' => ['required', 'integer', 'min:0', 'max:100'],
            'requested_date' => ['nullable', 'date', 'after_or_equal:today'],
            'requested_time' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'station_id' => ['nullable', 'uuid', 'exists:ev_charging_stations,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user?->isDriver()) {
                    $validator->errors()->add('driver', 'Only drivers can submit EV charge requests.');
                }
            },
        ];
    }
}
