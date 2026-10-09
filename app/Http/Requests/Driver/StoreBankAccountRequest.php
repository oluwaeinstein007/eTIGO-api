<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_code' => ['required', 'string', 'max:10'],
            'account_number' => ['required', 'string', 'digits:10'],
        ];
    }
}
