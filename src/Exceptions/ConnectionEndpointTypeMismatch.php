<?php

namespace iTRON\wpConnections\Exceptions;

class ConnectionEndpointTypeMismatch extends ConnectionWrongData
{
    public function __construct(string $side, string $expectedType, string $actualType)
    {
        parent::__construct(
            "Connection endpoint type mismatch: {$side} expected {$expectedType}, got {$actualType}.",
            307
        );
    }
}
