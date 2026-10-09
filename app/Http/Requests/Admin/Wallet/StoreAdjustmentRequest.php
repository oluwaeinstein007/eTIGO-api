<?php

namespace App\Http\Requests\Admin\Wallet;

use App\Enums\AdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid', 'exists:accounts,id'],
            'type' => ['required', Rule::enum(AdjustmentType::class)],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
