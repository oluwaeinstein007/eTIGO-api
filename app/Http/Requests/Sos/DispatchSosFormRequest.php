<?php

namespace App\Http\Requests\Sos;

use Illuminate\Foundation\Http\FormRequest;

class DispatchSosFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Whether the incident can be dispatched (open, not already dispatched)
     * is checked under a row lock in SosService::dispatch.
     */
    public function rules(): array
    {
        return [
            'emergency_service_type' => ['required', 'string', 'in:police,ambulance,fire,all'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
