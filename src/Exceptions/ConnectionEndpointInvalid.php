<?php

namespace iTRON\wpConnections\Exceptions;

class ConnectionEndpointInvalid extends ConnectionWrongData
{
    public function __construct(string $side, int $entityId)
    {
        parent::__construct("Invalid connection endpoint ID: {$side}={$entityId}.", 305);
    }
}
