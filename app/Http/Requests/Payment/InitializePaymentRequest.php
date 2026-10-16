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
        // The amount is set by the server (wallet card_verification_amount).
        // amount and currency are still accepted so older app builds that
        // send them keep working; they are ignored.
        return [
            'amount' => ['sometimes', 'numeric'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'redirect_url' => ['required', 'url'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['string', 'in:card,bank_transfer,ussd'],
        ];
    }
}
