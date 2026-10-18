<?php

namespace App\Exceptions;

/**
 * A city launch-status change that is not allowed: a transition that does
 * not exist, or a first launch that fails the readiness checklist.
 * Controllers answer 422 with the message and the readiness report.
 */
class CityLaunchException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $readiness
     */
    public function __construct(string $message, public readonly ?array $readiness = null)
    {
        parent::__construct($message);
    }
}
