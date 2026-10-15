<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Thrown when a state machine (ride, SOS incident) is asked to perform a
 * transition its current state does not allow — usually because a
 * concurrent request changed the state first. Rendered as 409 Conflict.
 */
class InvalidStateTransitionException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly ?string $currentState = null,
        public readonly ?string $requestedState = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'error_code' => 'INVALID_STATE_TRANSITION',
            'current_status' => $this->currentState,
        ], fn ($value) => $value !== null), 409);
    }
}
