<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Services\PaymentService;
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
            'source' => ['nullable', Rule::enum(PaymentMethod::class)->only([PaymentMethod::Card, PaymentMethod::Wallet, PaymentMethod::Cash])],
        ];
    }

    /**
     * `source` defaults to the ride's payment method. A bank-transfer ride was
     * a one-off checkout with nothing to charge a tip to, so it must name one.
     * A card tip whose charge outcome is still unknown may be retried: the
     * service checks the earlier charge before charging again.
     */
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

                if ($payment->tip_amount > 0 && ! app(PaymentService::class)->hasUnconfirmedCardTip($payment)) {
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
