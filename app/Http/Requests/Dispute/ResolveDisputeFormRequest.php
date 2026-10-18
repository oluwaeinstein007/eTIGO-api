<?php

namespace App\Http\Requests\Dispute;

use App\Enums\DisputeRefundMethod;
use App\Enums\DisputeStatus;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Services\ScheduledRideCancellationFeeService;
use App\Services\WalletRefundService;
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
     * Refunds go to the wallet by default (OQ-27); a card refund needs
     * Finance approval.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([DisputeStatus::Resolved->value, DisputeStatus::Dismissed->value])],
            'resolution_notes' => ['required', 'string', 'min:10', 'max:2000'],
            'refund_amount' => ['nullable', 'numeric', 'min:1'],
            'refund_method' => ['nullable', 'string', Rule::enum(DisputeRefundMethod::class)],
            'clawback_driver_earnings' => ['sometimes', 'boolean'],
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
                $amount = (float) $this->input('refund_amount');

                // A disputed late-cancellation fee: refunded to the wallet, or
                // waived in full while still owed.
                if ($ride->status !== RideStatus::Completed && ($fees = app(ScheduledRideCancellationFeeService::class))->disputableFee($ride) !== null) {
                    $remainingKobo = $fees->refundableKobo($ride);

                    if ($this->input('refund_method') === DisputeRefundMethod::Card->value) {
                        $validator->errors()->add('refund_amount', 'A cancellation fee is refunded to the wallet, or waived while it is still owed.');
                    } elseif ((int) round($amount * 100) > $remainingKobo) {
                        $validator->errors()->add('refund_amount', 'Refund cannot exceed the remaining refundable amount of ₦'.number_format($remainingKobo / 100, 2).'.');
                    }

                    return;
                }

                if (! $payment || $ride->status !== RideStatus::Completed) {
                    $validator->errors()->add('refund_amount', 'No payment found for this ride.');

                    return;
                }

                $remainingKobo = app(WalletRefundService::class)->remainingRefundableKobo($ride);

                if ($this->input('refund_method') === DisputeRefundMethod::Card->value) {
                    if ($payment->method !== PaymentMethod::Card) {
                        $validator->errors()->add('refund_amount', 'Card refunds can only be issued for card payments. Refund to the wallet instead.');

                        return;
                    }

                    if (! $payment->gateway_transaction_id) {
                        $validator->errors()->add('refund_amount', 'This payment has no gateway transaction and cannot be refunded to the card.');

                        return;
                    }
                }

                if ((int) round($amount * 100) > $remainingKobo) {
                    $validator->errors()->add('refund_amount', 'Refund cannot exceed the remaining refundable amount of ₦'.number_format($remainingKobo / 100, 2).'.');
                }
            },
        ];
    }
}
