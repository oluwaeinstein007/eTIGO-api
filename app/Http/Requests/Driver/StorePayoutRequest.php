<?php

namespace App\Http\Requests\Driver;

use App\Models\AppSetting;
use Illuminate\Foundation\Http\FormRequest;

class StorePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:'.AppSetting::wallet('min_payout')],
        ];
    }

    public function messages(): array
    {
        $minNaira = AppSetting::wallet('min_payout') / 100;

        return [
            'amount.min' => "Minimum payout amount is ₦{$minNaira}.",
        ];
    }
}
