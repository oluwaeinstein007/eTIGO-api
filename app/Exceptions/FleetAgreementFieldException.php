<?php

namespace App\Exceptions;

use DomainException;

/**
 * A change to a fleet agreement refused because of one field's value, so the
 * dashboard can show the message beside that field.
 */
class FleetAgreementFieldException extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
