<?php

namespace App\Http\Requests\Wallet;

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
            'amount' => ['required', 'integer', 'min:'.config('wallet.min_topup', 50000)],
            'callback_url' => ['required', 'url'],
        ];
    }

    public function messages(): array
    {
        $minNaira = config('wallet.min_topup', 50000) / 100;

        return [
            'amount.min' => "Minimum top-up amount is ₦{$minNaira}.",
        ];
    }
}
