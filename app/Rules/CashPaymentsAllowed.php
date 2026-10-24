<?php

namespace App\Rules;

use App\Enums\PaymentMethod;
use App\Models\AppSetting;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses cash for a new booking (or a booking's new payment method) while
 * cash payments are switched off (wallet.cash_payments_enabled).
 */
class CashPaymentsAllowed implements ValidationRule
{
    public const MESSAGE = "Cash isn't accepted. Pay by card or from your wallet.";

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === PaymentMethod::Cash->value && ! AppSetting::cashPaymentsEnabled()) {
            $fail(self::MESSAGE);
        }
    }
}
