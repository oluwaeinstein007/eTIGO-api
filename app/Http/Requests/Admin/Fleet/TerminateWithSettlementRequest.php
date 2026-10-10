<?php

namespace App\Http\Requests\Admin\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class TerminateWithSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'vehicle_return_status' => ['nullable', 'string', 'in:returned,pending_return,not_returned,damaged'],
            'outstanding_amount' => ['nullable', 'numeric', 'min:0'],
            'settlement_amount' => ['nullable', 'numeric', 'min:0'],
            'settlement_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
