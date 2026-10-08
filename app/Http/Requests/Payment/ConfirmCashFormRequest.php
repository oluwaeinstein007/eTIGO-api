<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmCashFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ride = $this->route('ride');

        return $ride && $ride->driver_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $ride = $this->route('ride');

                if (! $ride) {
                    return;
                }

                if ($ride->status !== RideStatus::Completed) {
                    $validator->errors()->add('ride', 'Can only confirm cash for completed rides.');
                }

                if ($ride->payment_method !== PaymentMethod::Cash) {
                    $validator->errors()->add('ride', 'This ride is not a cash payment.');
                }

                $payment = $ride->payment;
                if ($payment && $payment->status !== PaymentStatus::PendingCollection) {
                    $validator->errors()->add('ride', 'Cash has already been confirmed or payment is in an unexpected state.');
                }
            },
        ];
    }
}
