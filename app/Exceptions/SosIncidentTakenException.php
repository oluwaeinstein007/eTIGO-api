<?php

namespace App\Exceptions;

use App\Models\SosIncident;
use RuntimeException;

/**
 * Another operator took the incident after the caller last looked at it, so
 * the caller's take was refused rather than moving it away from them.
 */
class SosIncidentTakenException extends RuntimeException
{
    public function __construct(public readonly SosIncident $incident)
    {
        parent::__construct('Another operator has taken this incident.');
    }
}
