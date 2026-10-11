<?php

namespace App\Http\Requests\EvStation;

use App\Enums\EvStallStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManageStallFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stalls' => ['required', 'array', 'min:1'],
            'stalls.*.stall_number' => ['required', 'integer', 'min:1'],
            'stalls.*.status' => ['sometimes', Rule::in(array_column(EvStallStatus::cases(), 'value'))],
        ];
    }
}
