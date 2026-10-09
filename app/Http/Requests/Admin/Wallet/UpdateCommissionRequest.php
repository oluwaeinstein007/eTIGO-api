<?php

namespace App\Http\Requests\Admin\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'driver_id' => ['sometimes', 'nullable', 'uuid', 'exists:drivers,id'],
        ];
    }
}
