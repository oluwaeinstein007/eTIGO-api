<?php

namespace App\Http\Requests\OfflineFlag;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmDeactivationFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A permanent deactivation always has its reason on record.
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
