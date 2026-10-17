<?php

namespace App\Http\Requests\Sos;

use Illuminate\Foundation\Http\FormRequest;

class LogSosCallFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'called_party' => ['sometimes', 'string', 'in:passenger,driver,emergency_contact,other'],
            'outcome' => ['required', 'string', 'in:reached,no_answer,busy,unreachable'],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
