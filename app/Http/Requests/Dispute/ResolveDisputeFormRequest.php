<?php

namespace App\Http\Requests\Dispute;

use App\Enums\DisputeStatus;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ResolveDisputeFormRequest extends FormRequest
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
        return [
            'status' => ['required', 'string', Rule::in([DisputeStatus::Resolved->value, DisputeStatus::Dismissed->value])],
            'resolution_notes' => ['required', 'string', 'min:10', 'max:2000'],
            'refund_amount' => ['nullable', 'numeric', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $dispute = $this->route('dispute');

                if ($dispute->status->isTerminal()) {
                    $validator->errors()->add('status', 'This dispute has already been resolved or dismissed.');

                    return;
                }

                if (! $this->filled('refund_amount')) {
                    return;
                }

                if ($this->input('status') !== DisputeStatus::Resolved->value) {
                    $validator->errors()->add('refund_amount', 'Refunds can only be issued when resolving a dispute.');

                    return;
                }

                $ride = $dispute->ride;
                $payment = $ride->payment;

                if (! $payment) {
                    $validator->errors()->add('refund_amount', 'No payment found for this ride.');

                    return;
                }

                if ($payment->method !== PaymentMethod::Card) {
                    $validator->errors()->add('refund_amount', 'Refunds can only be issued for card payments.');

                    return;
                }

                if (! $payment->gateway_transaction_id) {
                    $validator->errors()->add('refund_amount', 'This payment has no gateway transaction and cannot be refunded.');

                    return;
                }

                if ((float) $this->input('refund_amount') > (float) $payment->amount) {
                    $validator->errors()->add('refund_amount', 'Refund amount cannot exceed the payment amount of '.$payment->amount.'.');
                }
            },
        ];
    }
}
