<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTipFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ride = $this->route('ride');

        return $ride && $ride->passenger_id === $this->user()->id;
    }

    public function rules(): array
    {
        $tipSettings = AppSetting::getTipSettings();

        return [
            'amount' => ['required', 'numeric', "min:{$tipSettings['min_amount']}", "max:{$tipSettings['max_amount']}"],
            // How the tip is paid; defaults to the ride's own method.
            'source' => ['nullable', Rule::enum(PaymentMethod::class)->only([PaymentMethod::Card, PaymentMethod::Wallet, PaymentMethod::Cash])],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $ride = $this->route('ride');

                if ($ride && $ride->status !== RideStatus::Completed) {
                    $validator->errors()->add('ride', 'Tips can only be added to completed rides.');
                }

                $source = $this->input('source');

                if ($ride && $ride->payment_method === PaymentMethod::BankTransfer && $source === null) {
                    // A bank transfer is a one-off checkout: there is nothing to charge a tip to.
                    $validator->errors()->add('source', 'Choose how to pay the tip: wallet or card.');

                    return;
                }

                if ($ride && $source === PaymentMethod::Cash->value && $ride->payment_method !== PaymentMethod::Cash) {
                    $validator->errors()->add('source', 'Cash tips can only be recorded on cash rides.');

                    return;
                }

                $payment = $ride?->payment;

                if (! $payment) {
                    $validator->errors()->add('ride', 'No payment record found for this ride.');

                    return;
                }

                if ($payment->status === PaymentStatus::Failed) {
                    $validator->errors()->add('ride', 'Cannot add a tip to a failed payment.');

                    return;
                }

                if ($payment->tip_amount > 0) {
                    $validator->errors()->add('amount', 'A tip has already been added to this ride.');
                }
            },
        ];
    }

    public function messages(): array
    {
        $tipSettings = AppSetting::getTipSettings();

        return [
            'amount.min' => 'Minimum tip is ₦'.number_format($tipSettings['min_amount']).'.',
            'amount.max' => 'Maximum tip is ₦'.number_format($tipSettings['max_amount']).'.',
        ];
    }
}
