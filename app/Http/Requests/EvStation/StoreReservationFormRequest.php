<?php

namespace App\Http\Requests\EvStation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estimated_charge_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $station = $this->route('station');

                if (! $station) {
                    $validator->errors()->add('station', 'Station not found.');
                    return;
                }

                if (! $station->isOperational()) {
                    $validator->errors()->add('station', 'This station is not currently operational.');
                    return;
                }

                $user = $this->user();

                if (! $user?->isDriver()) {
                    $validator->errors()->add('driver', 'Only drivers can reserve EV charging stalls.');
                }
            },
        ];
    }
}
