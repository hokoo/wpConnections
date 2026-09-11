<?php

namespace iTRON\wpConnections\Exceptions;

class ConnectionEndpointNotFound extends ConnectionWrongData
{
    public function __construct(string $side, int $entityId)
    {
        parent::__construct("Connection endpoint entity not found: {$side}={$entityId}.", 306);
    }
}
