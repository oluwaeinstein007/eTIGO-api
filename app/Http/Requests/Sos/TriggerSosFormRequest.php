<?php

namespace App\Http\Requests\Sos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TriggerSosFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gps_lat' => ['required', 'numeric', 'between:-90,90'],
            'gps_lng' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ride = $this->route('ride');

                if (! $ride) {
                    $validator->errors()->add('ride', 'Ride not found.');

                    return;
                }

                if (! $ride->status->isActive()) {
                    $validator->errors()->add('ride', 'SOS can only be triggered on an active ride.');

                    return;
                }

                $user = $this->user();
                $isParticipant = $ride->passenger_id === $user->id
                    || $ride->driver_id === $user->id;

                if (! $isParticipant) {
                    $validator->errors()->add('ride', 'You are not a participant of this ride.');
                }
            },
        ];
    }
}
