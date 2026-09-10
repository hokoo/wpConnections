<?php

namespace iTRON\wpConnections\Exceptions;

class ConnectionEndpointTypeUnsupported extends ConnectionWrongData
{
    public function __construct(string $side, string $entityType)
    {
        parent::__construct("Unsupported connection endpoint type: {$side}={$entityType}.", 308);
    }
}
