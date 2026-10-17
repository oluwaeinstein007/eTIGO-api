<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class InitializePaymentRequest extends FormRequest
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
        // The server sets the amount (wallet card_verification_amount) and
        // only takes cards. amount, currency and channels are still accepted
        // so older app builds that send them keep working; they are ignored.
        return [
            'amount' => ['sometimes', 'numeric'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'redirect_url' => ['required', 'url'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['string'],
        ];
    }
}
