<?php

namespace iTRON\wpConnections\Exceptions;

use Throwable;

class ConnectionWrongData extends Exception
{
    public function __construct(string $message = '', $code = 300, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
