<?php

namespace iTRON\wpConnections\Exceptions;

use Throwable;

class RelationWrongData extends Exception
{
    public function __construct(string $message = '', $code = 400, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
