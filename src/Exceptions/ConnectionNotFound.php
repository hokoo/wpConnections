<?php

namespace iTRON\wpConnections\Exceptions;

use Throwable;

class ConnectionNotFound extends Exception
{
    public function __construct(Throwable $previous = null)
    {
        parent::__construct('Connection not found.', 2, $previous);
    }
}
