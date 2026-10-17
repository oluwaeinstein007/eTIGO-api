<?php

namespace App\Http\Requests\Wallet;

use App\Models\AppSetting;
use Illuminate\Foundation\Http\FormRequest;

class StoreTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:'.AppSetting::wallet('min_topup')],
            'callback_url' => ['required', 'url'],
            'save_card' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        $minNaira = AppSetting::wallet('min_topup') / 100;

        return [
            'amount.min' => "Minimum top-up amount is ₦{$minNaira}.",
        ];
    }
}
