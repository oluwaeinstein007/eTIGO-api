<?php

namespace App\Http\Requests\Payment;

use App\Enums\RideStatus;
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
        return [
            'amount' => ['required', 'numeric', 'min:50', 'max:50000'],
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

                if ($ride?->payment?->tip_amount > 0) {
                    $validator->errors()->add('amount', 'A tip has already been added to this ride.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Minimum tip is ₦50.',
            'amount.max' => 'Maximum tip is ₦50,000.',
        ];
    }
}
