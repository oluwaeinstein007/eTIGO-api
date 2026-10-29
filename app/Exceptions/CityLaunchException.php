<?php

namespace App\Exceptions;

/**
 * A city launch-status change that is not allowed: a transition that does
 * not exist, a first launch that fails the readiness checklist (422 with
 * the report), or a close refused while trips or bookings are open (409
 * with the counts). Controllers answer with the status, the message, the
 * readiness report and the extra fields.
 */
class CityLaunchException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $readiness
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly ?array $readiness = null,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return array_filter([
            'message' => $this->getMessage(),
            'readiness' => $this->readiness,
            ...$this->context,
        ], fn ($value) => $value !== null);
    }
}
