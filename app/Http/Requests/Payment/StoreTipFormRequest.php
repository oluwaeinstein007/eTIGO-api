<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use Illuminate\Foundation\Http\FormRequest;

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
