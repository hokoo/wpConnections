<?php

namespace iTRON\wpConnections\Exceptions;

use Throwable;

class MissingParameters extends Exception
{
    public function __construct($message = 'Missing required fields: ', $code = 4, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
