<?php

namespace App\Exceptions;

/**
 * No reliable distance is available for a booking: every routing provider
 * failed and the straight-line fallback distance exceeds
 * pricing.max_approximate_distance_km. Booking on an unreliable long-range
 * guess risks a fare far from the real road distance, so the ride is
 * refused rather than quoted. The passenger should retry shortly.
 */
class FareEstimateUnavailableException extends \RuntimeException
{
    public function __construct(string $message = 'We could not calculate a reliable fare for this trip right now. Please try again shortly.')
    {
        parent::__construct($message);
    }
}
