<?php

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;

/**
 * Sign-in requests normalise the phone before validating it, so the number
 * a code is sent to, the one it is checked against and the one an account
 * is opened with are the same however the app formatted it.
 */
trait NormalizesPhone
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }
    }
}
