<?php

namespace App\Http\Requests\Admin\Wallet;

use App\Enums\AccountType;
use App\Enums\AdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Adjustments move money on a passenger's wallet or a driver's available
     * earnings (the driver ledger's account), never on E-tiGo's own accounts.
     */
    public function rules(): array
    {
        return [
            'account_id' => [
                'required',
                'uuid',
                Rule::exists('accounts', 'id')->whereIn('type', [
                    AccountType::PassengerWallet->value,
                    AccountType::DriverEarningsAvailable->value,
                ]),
            ],
            'type' => ['required', Rule::enum(AdjustmentType::class)],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.exists' => 'Choose a passenger wallet or a driver ledger account.',
        ];
    }
}
