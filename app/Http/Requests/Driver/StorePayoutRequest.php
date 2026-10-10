<?php

namespace App\Http\Requests\Driver;

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
            'amount' => ['required', 'integer', 'min:'.config('wallet.min_payout', 100000)],
        ];
    }

    public function messages(): array
    {
        $minNaira = config('wallet.min_payout', 100000) / 100;

        return [
            'amount.min' => "Minimum payout amount is ₦{$minNaira}.",
        ];
    }
}
