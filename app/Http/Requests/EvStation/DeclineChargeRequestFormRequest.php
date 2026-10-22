<?php

namespace App\Http\Requests\EvStation;

use Illuminate\Foundation\Http\FormRequest;

class DeclineChargeRequestFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Shown to the driver in the decline notification and on the request.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
