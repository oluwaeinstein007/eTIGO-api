<?php

namespace App\Exceptions;

/**
 * A domain action the user is not allowed to take; controllers answer 403
 * with the message.
 */
class AccessDeniedException extends \RuntimeException {}
