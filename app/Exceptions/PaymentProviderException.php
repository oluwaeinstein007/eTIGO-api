<?php

namespace App\Exceptions;

/**
 * The payment provider answered a request with an error status.
 */
class PaymentProviderException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }

    /**
     * A 5xx says nothing about whether the request took effect, and a 429 only
     * asks us to slow down: in both cases try again later rather than treating
     * the request as refused.
     */
    public function isTransient(): bool
    {
        return $this->status >= 500 || $this->status === 429;
    }
}
