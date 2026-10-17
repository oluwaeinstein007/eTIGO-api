<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A wallet top-up that cannot be started or confirmed. The message is meant
 * for the passenger; status is the HTTP status to answer with.
 */
class TopupRefusedException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
