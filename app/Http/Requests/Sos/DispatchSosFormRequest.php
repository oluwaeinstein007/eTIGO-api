<?php

namespace App\Http\Requests\Sos;

use App\Enums\SosIncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DispatchSosFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'emergency_service_type' => ['required', 'string', 'in:police,ambulance,fire,all'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $incident = $this->route('incident');

                if (! $incident) {
                    return;
                }

                if ($incident->status !== SosIncidentStatus::OperatorAssigned) {
                    $validator->errors()->add(
                        'incident',
                        'An operator must be assigned before dispatching emergency services.',
                    );
                }
            },
        ];
    }
}
